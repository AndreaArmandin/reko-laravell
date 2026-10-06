<?php

use App\Gestionale\Actions\Requests\AnswerPropertyRequestQuestion;
use App\Gestionale\Actions\Requests\FinishPropertyRequest;
use App\Gestionale\Actions\Requests\SavePropertyRequest;
use App\Gestionale\Actions\Requests\StepPropertyRequest;
use App\Gestionale\Actions\SetRecordLifecycle;
use App\Gestionale\CommandRejected;
use App\Gestionale\Commands;
use App\Gestionale\Livewire\HandlesCommands;
use App\Gestionale\Questionnaire\AnswerPresenter;
use App\Gestionale\Questionnaire\ProfileFlow;
use App\Gestionale\Questionnaire\Questionnaire;
use App\Models\Contact;
use App\Models\PropertyMatch;
use App\Models\PropertyRequest;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/*
 * Richiesta: profilazione guidata in 5 passi (profile.tsx), salvataggio automatico di ogni risposta
 * (request.answer), punto di ripresa (request.step), conferma (request.finish), riepilogo,
 * approfondimento facoltativo e specifiche manuali; "Gestisci richiesta" (request.save);
 * archiviazione reversibile e risultati di compatibilità con gli immobili.
 */
new #[Layout('layouts::gestionale')] class extends Component {
    use HandlesCommands;
    use WithPagination;

    public PropertyRequest $request;

    public ?string $revision = null;

    /** @var array<string, mixed> form fields keyed by question id */
    public array $answers = [];

    public string $group = 'operation';

    public bool $review = false;

    public ?int $manageContactId = null;

    public string $manageStatus = '';

    public bool $confirmRemove = false;

    #[Computed]
    public function matches()
    {
        return PropertyMatch::query()->with('property')->where('agency_id', $this->request->agency_id)
            ->where('property_request_id', $this->request->id)->whereHas('property', fn ($q) => $q->whereNull('lifecycle_state'))
            ->orderByRaw('score DESC NULLS LAST')->orderByDesc('id')->paginate(10);
    }

    public function updateMatch(int $matchId, string $status): void
    {
        $this->authorize('update', $this->request);
        if (! in_array($status, ['Nuovo abbinamento', 'Proposto al cliente', 'Non compatibile', 'Rifiutato', 'In trattativa', 'Concluso'], true)) {
            abort(422);
        }
        $match = PropertyMatch::query()->where('agency_id', $this->request->agency_id)->where('property_request_id', $this->request->id)->findOrFail($matchId);
        $before = $match->status;
        $match->update(['status' => $status]);
        app(\App\Gestionale\Audit::class)->record('match.status', $match, ['before' => $before, 'after' => $status]);
        unset($this->matches);
        session()->flash('status', 'Stato dell’abbinamento aggiornato.');
    }

    public function mount(PropertyRequest $propertyRequest): void
    {
        $this->authorize('view', $propertyRequest);
        $this->request = $propertyRequest;
        $this->sync();
        $this->group = ProfileFlow::groupId($this->questionnaire, $propertyRequest->criteria, $propertyRequest->step_id);
        $this->review = Questionnaire::hasAnswer($propertyRequest->criteria['operation'] ?? null) && ($propertyRequest->finished || $propertyRequest->step_id === 'review');
    }

    private function sync(?PropertyRequest $fresh = null): void
    {
        if ($fresh) {
            $this->request = $fresh;
        }
        $this->revision = Commands::revision($this->request);
        $this->answers = [];
        foreach ($this->questionnaire->questions() as $q) {
            $this->answers[$q['id']] = AnswerPresenter::toField($q, $this->request->criteria[$q['id']] ?? null);
        }
        $this->manageContactId = $this->request->contact_id;
        $this->manageStatus = $this->request->status;
        unset($this->groups, $this->progress);
    }

    #[Computed]
    public function questionnaire(): Questionnaire
    {
        return Questionnaire::forAgency((int) $this->request->agency_id);
    }

    #[Computed]
    public function groups(): array
    {
        return ProfileFlow::groups($this->questionnaire, $this->request->criteria);
    }

    #[Computed]
    public function progress(): array
    {
        return ProfileFlow::baseCompleteness($this->questionnaire, $this->request->criteria);
    }

    #[Computed]
    public function editable(): bool
    {
        return auth()->user()->can('update', $this->request);
    }

    #[Computed]
    public function clients()
    {
        return Contact::query()->visibleTo($this->actor())->whereHas('clientProfile')->orderBy('display_name')->get(['id', 'display_name']);
    }

    /** Autosave: one request.answer per changed answer (650 ms debounce in the inputs). */
    public function updatedAnswers(mixed $value, string $key): void
    {
        $this->save(explode('.', $key)[0]);
    }

    public function clearAnswer(string $id): void
    {
        $question = $this->questionnaire->question($id);
        if ($question) {
            $this->answers[$id] = AnswerPresenter::toField($question, null);
            $this->save($id);
        }
    }

    public function classify(string $id, string $classification): void
    {
        $this->save($id, $classification);
    }

    private function save(string $id, ?string $classification = null): void
    {
        $question = $this->questionnaire->question($id);
        if (! $question || ! $this->editable) {
            return;
        }
        $value = AnswerPresenter::fromField($question, $this->answers[$id] ?? null);
        if ($classification === null && $value === ($this->request->criteria[$id] ?? null)) {
            return;
        }
        $this->resetErrorBag();
        try {
            $fresh = app(AnswerPropertyRequestQuestion::class)->handle($this->actor(), $this->request, array_filter([
                'question_id' => $id, 'value' => $value, 'classification' => $classification,
                'next_step' => $this->review ? 'review' : $this->group, 'expected_updated_at' => $this->revision,
            ], fn ($v, $k) => $k === 'value' || $v !== null, ARRAY_FILTER_USE_BOTH));
            $this->sync($fresh);
        } catch (CommandRejected $e) {
            $this->addError('answers.'.$id, $e->getMessage());
            if ($e->status() === 409) {
                Flux::toast(variant: 'danger', text: $e->getMessage());
            }
        }
    }

    public function goStep(string $id): void
    {
        $target = collect($this->groups)->first(fn ($g) => $g['id'] === $id || in_array($id, array_column($g['questions'], 'id'), true))['id']
            ?? ProfileFlow::groupId($this->questionnaire, $this->request->criteria, $id);
        $fresh = $this->command(fn () => app(StepPropertyRequest::class)->handle($this->actor(), $this->request, $target, $this->revision));
        if ($fresh) {
            $this->sync($fresh);
            $this->group = $target;
            $this->review = false;
        }
    }

    public function advance(): void
    {
        $ids = array_column($this->groups, 'id');
        $next = $ids[array_search($this->group, $ids, true) + 1] ?? null;
        $next ? $this->goStep($next) : $this->finish();
    }

    public function back(): void
    {
        $ids = array_column($this->groups, 'id');
        $index = array_search($this->group, $ids, true);
        if ($index > 0) {
            $this->goStep($ids[$index - 1]);
        }
    }

    public function openReview(): void
    {
        $fresh = $this->command(fn () => app(StepPropertyRequest::class)->handle($this->actor(), $this->request, 'review', $this->revision), 'Riepilogo salvato');
        if ($fresh) {
            $this->sync($fresh);
            $this->review = true;
        }
    }

    public function finish(): void
    {
        $fresh = $this->command(fn () => app(FinishPropertyRequest::class)->handle($this->actor(), $this->request, $this->revision),
            'Preferenze registrate. Puoi aggiornarle quando vuoi.');
        if ($fresh) {
            $this->sync($fresh);
            $this->review = true;
        }
    }

    public function manage(): void
    {
        $fresh = $this->command(fn () => app(SavePropertyRequest::class)->handle($this->actor(), [
            'contact_id' => $this->manageContactId, 'status' => $this->manageStatus, 'expected_updated_at' => $this->revision,
        ], $this->request), 'Richiesta aggiornata.');
        if ($fresh) {
            $this->sync($fresh);
            Flux::modal('manage-request')->close();
        }
    }

    public function lifecycle(string $mode): void
    {
        $done = $this->command(fn () => app(SetRecordLifecycle::class)->handle($this->actor(), $this->request, $mode, $this->confirmRemove),
            ['archive' => 'Richiesta archiviata.', 'remove' => 'Richiesta rimossa dalle liste.', 'restore' => 'Richiesta ripristinata.'][$mode] ?? null);
        if ($done) {
            $this->confirmRemove = false;
            $this->sync($this->request->fresh());
            Flux::modal('remove-request')->close();
        }
    }
}; ?>

@php
    $criteria = $request->criteria;
    $operationKnown = App\Gestionale\Questionnaire\Questionnaire::hasAnswer($criteria['operation'] ?? null);
    $groups = $this->groups;
    $index = max(0, array_search($group, array_column($groups, 'id'), true) ?: 0);
    $current = $groups[$index];
    $progress = $this->progress;
    $optional = App\Gestionale\Questionnaire\ProfileFlow::optionalQuestions($this->questionnaire, $criteria);
    $guidedIds = array_merge(...array_map(fn ($g) => array_column($g['questions'], 'id'), $groups));
    $specifics = array_values(array_filter($this->questionnaire->manual($criteria),
        fn ($q) => $q['id'] !== 'tags' && ! in_array($q['id'], App\Gestionale\Questionnaire\ProfileFlow::OPTIONAL_IDS, true) && ! in_array($q['id'], $guidedIds, true)));
    $archived = array_values(array_filter($this->questionnaire->questions(),
        fn ($q) => ($q['collection'] ?? '') === 'archived' && App\Gestionale\Questionnaire\Questionnaire::hasAnswer($criteria[$q['id']] ?? null)));
    $ready = collect($current['questions'])->every(fn ($q) => $q['skippable'] && $q['id'] !== 'operation' || App\Gestionale\Questionnaire\Questionnaire::hasAnswer($criteria[$q['id']] ?? null));
    $editable = $this->editable;
@endphp

<div class="flex flex-col gap-6">
    <div>
        <flux:link :href="route('gestionale.requests.index')" wire:navigate>← Tutte le richieste</flux:link>
        <div class="mt-2 flex flex-wrap items-center justify-between gap-4">
            <div>
                <flux:heading size="xl" level="1">{{ $request->title }}</flux:heading>
                <div class="mt-1 flex flex-wrap items-center gap-2">
                    <flux:badge size="sm">{{ $request->status }}</flux:badge>
                    @if ($request->lifecycle_state)
                        <flux:badge size="sm" color="zinc">{{ $request->lifecycle_state === 'removed' ? 'Rimossa dalle liste' : 'Archiviata' }}</flux:badge>
                    @endif
                    <flux:link :href="route('gestionale.clients.show', $request->contact_id)" wire:navigate>Apri scheda cliente · {{ $request->contact->display_name }}</flux:link>
                </div>
            </div>
            @if ($editable)
                <flux:modal.trigger name="manage-request"><flux:button icon="cog-6-tooth">Gestisci richiesta</flux:button></flux:modal.trigger>
            @endif
        </div>
        @if (! $request->finished)
            <flux:text size="sm" class="mt-2">Completa dopo: il profilo resta salvato e si riprende dal punto lasciato.</flux:text>
        @endif
    </div>

    <div class="flex gap-2 border-b border-zinc-200">
        <flux:button variant="ghost" class="rounded-b-none border-b-2 border-zinc-900">Profilazione</flux:button>
        <flux:button variant="ghost" href="#abbinamenti" wire:navigate>Abbinamenti</flux:button>
        <flux:button variant="ghost" :href="route('gestionale.activities.index')" wire:navigate>Agenda</flux:button>
    </div>

    @error('command') <flux:callout variant="danger" icon="exclamation-triangle" :heading="$message" /> @enderror

    <section class="flex flex-col gap-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <flux:text size="sm">Profilazione rapida</flux:text>
                <flux:heading size="lg">Le esigenze del cliente.</flux:heading>
            </div>
            <flux:badge>{{ $progress['answered'] }}/{{ $progress['total'] }} risposte del percorso base</flux:badge>
        </div>
        <details class="text-sm text-zinc-600">
            <summary class="cursor-pointer">Come compilare la richiesta</summary>
            <p class="mt-1">Raccogli ciò che cerca il cliente. Indica metri quadri e camere da letto, non vani catastali. I campi facoltativi possono restare vuoti: negli abbinamenti saranno segnalati i dati da verificare.</p>
        </details>
        <div class="h-2 w-full overflow-hidden rounded bg-zinc-200" role="progressbar" aria-label="Completezza del percorso base" aria-valuenow="{{ $progress['percent'] }}" aria-valuemin="0" aria-valuemax="100">
            <div class="h-2 bg-zinc-900" style="width: {{ $progress['percent'] }}%"></div>
        </div>
        @if ($optional !== [])
            <div>
                <flux:modal.trigger name="optional-questions">
                    <flux:button size="sm" :disabled="! $operationKnown">Approfondimento facoltativo{{ collect($optional)->contains(fn ($q) => App\Gestionale\Questionnaire\Questionnaire::hasAnswer($criteria[$q['id']] ?? null)) ? ' · dati già presenti' : '' }}</flux:button>
                </flux:modal.trigger>
            </div>
        @endif

        @if (! $review)
            <div class="flex items-center justify-between">
                <flux:button variant="ghost" size="sm" icon="arrow-left" wire:click="back" :disabled="$index === 0">Indietro</flux:button>
                <flux:text size="sm">Passo {{ $index + 1 }} di {{ count($groups) }}</flux:text>
                <flux:button variant="ghost" size="sm" wire:click="openReview" :disabled="! $operationKnown">Riepilogo</flux:button>
            </div>

            <flux:card class="flex flex-col gap-5">
                <flux:heading size="lg">{{ $current['title'] }}</flux:heading>
                @if (! $operationKnown && $current['id'] !== 'operation')
                    <flux:text>Scegli prima Acquisto o Locazione.</flux:text>
                @endif
                @foreach ($current['questions'] as $question)
                    @include('gestionale.partials.question', ['question' => $question, 'disabled' => ! $editable || (! $operationKnown && $question['id'] !== 'operation')])
                @endforeach
                @if ($editable)
                    <div class="flex justify-end gap-2">
                        @if ($current['id'] === 'notes')
                            <flux:modal.trigger name="specifics"><flux:button>Altre specifiche facoltative</flux:button></flux:modal.trigger>
                        @endif
                        <flux:button variant="primary" icon-trailing="arrow-right" wire:click="advance" :disabled="! $ready">
                            {{ $current['id'] === 'notes' ? 'Concludi e vedi il riepilogo' : 'Continua' }}
                        </flux:button>
                    </div>
                @endif
            </flux:card>
        @else
            @php($missing = $progress['total'] - $progress['answered'])
            <flux:callout icon="check-circle" variant="success">
                <flux:callout.heading>{{ $missing > 0 ? 'Salvato · '.$missing.($missing === 1 ? ' risposta ancora da definire' : ' risposte ancora da definire') : 'Salvato · tutte le risposte del percorso base sono presenti' }}</flux:callout.heading>
                <flux:callout.text>I campi facoltativi possono restare da definire; gli abbinamenti useranno solo i dati disponibili.</flux:callout.text>
            </flux:callout>
            @php($firstMissing = collect($groups)->flatMap(fn ($g) => $g['questions'])->first(fn ($q) => $q['id'] !== 'tags' && ! App\Gestionale\Questionnaire\Questionnaire::hasAnswer($criteria[$q['id']] ?? null)))
            @if ($editable)
                <div class="flex flex-wrap gap-2">
                    @if ($firstMissing)
                        <flux:button size="sm" wire:click="goStep('{{ $firstMissing['id'] }}')">Completa le risposte mancanti</flux:button>
                    @endif
                    <flux:button size="sm" icon="pencil" wire:click="goStep('{{ $groups[0]['id'] }}')">Modifica parametri richiesta</flux:button>
                    <flux:modal.trigger name="specifics"><flux:button size="sm" variant="ghost">Note e tag</flux:button></flux:modal.trigger>
                </div>
            @endif
            @if (! empty($criteria['tags']))
                <div class="flex flex-wrap gap-1">@foreach ($criteria['tags'] as $tag)<flux:badge size="sm">{{ $tag }}</flux:badge>@endforeach</div>
            @endif
            <flux:heading>Riepilogo delle preferenze</flux:heading>
            <div class="grid gap-2 md:grid-cols-2">
                @foreach (collect($groups)->flatMap(fn ($g) => $g['questions'])->filter(fn ($q) => $q['id'] !== 'tags') as $q)
                    <button type="button" @if ($editable) wire:click="goStep('{{ $q['id'] }}')" @endif class="flex items-center justify-between rounded-lg border border-zinc-200 bg-white p-3 text-start hover:bg-zinc-50" wire:key="rv-{{ $q['id'] }}">
                        <span>
                            <span class="block text-xs text-zinc-500">{{ App\Gestionale\Questionnaire\AnswerPresenter::questionLabel($q) }}</span>
                            <span class="block font-medium">{{ App\Gestionale\Questionnaire\AnswerPresenter::answerLabel($q['id'], $criteria[$q['id']] ?? null) }}</span>
                        </span>
                        <flux:icon.pencil class="size-4 text-zinc-400" />
                    </button>
                @endforeach
            </div>
            @if (! $request->finished && $editable)
                <div><flux:button variant="primary" wire:click="finish">Conferma le preferenze disponibili</flux:button></div>
            @endif
        @endif
    </section>

    <section id="abbinamenti" class="flex flex-col gap-3">
        <div class="flex items-center justify-between"><div><flux:heading size="lg">Immobili compatibili</flux:heading><flux:text size="sm">Compatibilità indicativa calcolata sulle risposte non riservate e sui pesi dell’agenzia.</flux:text></div><flux:badge>{{ $this->matches->total() }} risultati</flux:badge></div>
        @forelse ($this->matches as $match)
            <flux:card class="flex flex-col gap-3">
                <div class="flex flex-wrap items-start justify-between gap-3"><div><flux:link :href="route('gestionale.properties.show', $match->property)" wire:navigate><flux:heading size="md">{{ $match->property?->title ?? 'Immobile' }}</flux:heading></flux:link><flux:text>{{ $match->property?->address }} · {{ $match->property?->city }}</flux:text></div>
                    <div class="flex items-center gap-2">@if ($match->score !== null)<flux:badge variant="pill">{{ $match->result['level'] ?? 'Compatibilità' }} · {{ number_format((float) $match->score, 0) }}%</flux:badge>@endif<flux:badge>{{ $match->status }}</flux:badge></div></div>
                @if ($match->result['reasons'] ?? [])<ul class="list-inside list-disc text-sm text-zinc-600">@foreach (array_slice($match->result['reasons'], 0, 3) as $reason)<li>{{ $reason }}</li>@endforeach</ul>@endif
                @if ($editable && $match->status === 'Nuovo abbinamento')<div class="flex flex-wrap gap-2"><flux:button size="sm" wire:click="updateMatch({{ $match->id }}, 'Proposto al cliente')" wire:confirm="Confermi che l’immobile è stato proposto al cliente?">Segna come proposto</flux:button><flux:button size="sm" variant="ghost" wire:click="updateMatch({{ $match->id }}, 'Non compatibile')">Non compatibile</flux:button></div>@endif
            </flux:card>
        @empty
            <flux:card><flux:text>Nessun immobile in portafoglio compatibile per questa richiesta. Gli abbinamenti si aggiornano quando cambiano le preferenze o il portafoglio.</flux:text></flux:card>
        @endforelse
        {{ $this->matches->links() }}
    </section>

    <flux:card class="flex flex-col gap-3">
        <flux:heading>Archiviazione della richiesta</flux:heading>
        <flux:text size="sm">Operazioni reversibili: risposte e storico non vengono cancellati.</flux:text>
        <div class="flex flex-wrap gap-2">
            @if ($request->lifecycle_state)
                <flux:button wire:click="lifecycle('restore')">Ripristina</flux:button>
            @elseif (auth()->user()->can('archive', $request))
                <flux:button wire:click="lifecycle('archive')">Archivia</flux:button>
            @endif
            @if ($this->actor()->isAdmin() && $request->lifecycle_state !== 'removed')
                <flux:modal.trigger name="remove-request"><flux:button variant="danger">Rimuovi dalle liste</flux:button></flux:modal.trigger>
            @endif
        </div>
    </flux:card>

    {{-- Gestisci richiesta (RequestForm): cambio cliente e stato --}}
    <flux:modal name="manage-request" class="max-w-lg">
        <form wire:submit="manage" class="flex flex-col gap-4">
            <flux:heading size="lg">Gestisci richiesta</flux:heading>
            <flux:select wire:model="manageContactId" label="Cliente">
                @foreach ($this->clients as $c)
                    <flux:select.option :value="$c->id">{{ $c->display_name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:text size="sm">Per cambiare cliente scegli una scheda già presente: il referente della richiesta diventa quello del cliente.</flux:text>
            <flux:select wire:model="manageStatus" label="Gestione della richiesta">
                @foreach (App\Models\PropertyRequest::STATUSES as $s)
                    <flux:select.option :value="$s">{{ $s }}</flux:select.option>
                @endforeach
            </flux:select>
            @error('contact_id') <flux:text class="text-red-600">{{ $message }}</flux:text> @enderror
            @error('status') <flux:text class="text-red-600">{{ $message }}</flux:text> @enderror
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Annulla</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">Salva collegamento</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="optional-questions" class="max-w-2xl">
        <div class="flex flex-col gap-4">
            <flux:heading size="lg">Situazione abitativa e mutuo</flux:heading>
            <flux:text size="sm">Domande facoltative: non entrano nel punteggio.</flux:text>
            @foreach ($optional as $question)
                @include('gestionale.partials.question', ['question' => $question, 'disabled' => ! $editable || ! $operationKnown])
            @endforeach
        </div>
    </flux:modal>

    <flux:modal name="specifics" class="max-w-2xl">
        <div class="flex flex-col gap-4">
            <flux:heading size="lg">Note, tag e specifiche</flux:heading>
            @foreach (array_filter($this->questionnaire->visible($criteria), fn ($q) => in_array($q['id'], ['notes', 'tags'], true)) as $question)
                @include('gestionale.partials.question', ['question' => $question, 'disabled' => ! $editable || ! $operationKnown])
            @endforeach
            <flux:heading>Aggiungi o modifica una specifica</flux:heading>
            @foreach ($specifics as $question)
                @include('gestionale.partials.question', ['question' => $question, 'disabled' => ! $editable || ! $operationKnown])
            @endforeach
            @if ($archived !== [])
                <flux:heading>Informazioni raccolte in precedenza</flux:heading>
                @foreach ($archived as $q)
                    <flux:text size="sm">{{ App\Gestionale\Questionnaire\AnswerPresenter::questionLabel($q) }}: <strong>{{ App\Gestionale\Questionnaire\AnswerPresenter::answerLabel($q['id'], $criteria[$q['id']]) }}</strong></flux:text>
                @endforeach
            @endif
        </div>
    </flux:modal>

    <flux:modal name="remove-request" class="max-w-md">
        <div class="flex flex-col gap-4">
            <flux:heading size="lg">Rimuovi dalle liste</flux:heading>
            <flux:text>La richiesta esce dalle liste ma resta conservata. Puoi ripristinarla in qualsiasi momento.</flux:text>
            <flux:checkbox wire:model="confirmRemove" label="Confermo la rimozione reversibile dalla lista" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Annulla</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="lifecycle('remove')">Rimuovi</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
