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
use App\Models\PropertyRequest;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

/*
 * Richiesta: profilazione guidata in 5 passi (profile.tsx), salvataggio automatico di ogni risposta
 * (request.answer), punto di ripresa (request.step), conferma (request.finish), riepilogo,
 * approfondimento facoltativo e specifiche manuali; "Gestisci richiesta" (request.save);
 * archiviazione reversibile e abbinamenti con gli immobili (<livewire:gestionale.crm-matches>).
 */
new #[Layout('layouts::gestionale')] class extends Component {
    use HandlesCommands;

    public PropertyRequest $request;

    public ?string $revision = null;

    /** @var array<string, mixed> form fields keyed by question id */
    public array $answers = [];

    public string $group = 'operation';

    public bool $review = false;

    public string $activeTab = 'Profilazione';

    public bool $manageRequestOpen = false;

    public ?int $manageContactId = null;

    public string $manageName = '';

    public string $managePhone = '';

    public string $manageEmail = '';

    public string $manageStatus = '';

    public bool $optionalQuestionsOpen = false;

    public bool $specificsOpen = false;

    public string $specificNotes = '';

    /** @var list<string> */
    public array $specificTags = [];

    public string $specificSelected = '';

    public mixed $specificValue = null;

    public function mount(PropertyRequest $propertyRequest): void
    {
        $this->authorize('view', $propertyRequest);
        $this->request = $propertyRequest;
        $this->sync();
        $this->group = ProfileFlow::groupId($this->questionnaire, $propertyRequest->criteria, $propertyRequest->step_id);
        $this->review = Questionnaire::hasAnswer($propertyRequest->criteria['operation'] ?? null) && ($propertyRequest->finished || $propertyRequest->step_id === 'review');
        $this->activeTab = $propertyRequest->finished ? 'Abbinamenti' : 'Profilazione';
    }

    public function selectTab(string $tab): void
    {
        if (! in_array($tab, ['Profilazione', 'Abbinamenti', 'Cronologia'], true)) {
            return;
        }

        if ($tab === 'Abbinamenti' && ! Questionnaire::hasAnswer($this->request->criteria['operation'] ?? null)) {
            return;
        }

        $this->activeTab = $tab;
    }

    public function openManageRequest(): void
    {
        abort_unless($this->editable, 403);
        $contact = $this->request->contact()->with(['primaryPhone', 'primaryEmail'])->first();
        $this->manageContactId = $contact->id;
        $this->manageName = $contact->display_name;
        $this->managePhone = (string) $contact->primaryPhone?->value;
        $this->manageEmail = (string) $contact->primaryEmail?->value;
        $this->manageStatus = $this->request->status;
        $this->resetErrorBag();
        $this->manageRequestOpen = true;
    }

    public function closeManageRequest(): void
    {
        $this->manageRequestOpen = false;
        $this->resetErrorBag();
    }

    /** client-entry.tsx: digitare di nuovo il nome scollega il cliente scelto. */
    public function updatedManageName(): void
    {
        $this->manageContactId = null;
    }

    public function pickClient(int $id): void
    {
        abort_unless($this->manageRequestOpen && $this->editable, 403);
        $contact = Contact::query()->visibleTo($this->actor())->whereHas('clientProfile')->with(['primaryPhone', 'primaryEmail'])->findOrFail($id);
        $this->manageContactId = $contact->id;
        $this->manageName = $contact->display_name;
        $this->managePhone = (string) $contact->primaryPhone?->value;
        $this->manageEmail = (string) $contact->primaryEmail?->value;
        $this->resetErrorBag();
        unset($this->manageSuggestions);
    }

    /** Clienti già presenti: da 2 caratteri, al massimo 6, tra quelli visibili all'operatore. */
    #[Computed]
    public function manageSuggestions()
    {
        $term = trim($this->manageName);
        if ($this->manageContactId !== null || mb_strlen($term) < 2) {
            return collect();
        }
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';

        return Contact::query()->visibleTo($this->actor())->whereHas('clientProfile')
            ->where(fn ($q) => $q->where('display_name', 'ilike', $like)->orWhereHas('channels', fn ($c) => $c->where('value', 'ilike', $like)))
            ->with(['primaryPhone', 'primaryEmail'])->orderBy('display_name')->limit(6)->get();
    }

    public function openOptionalQuestions(): void
    {
        abort_unless($this->editable && Questionnaire::hasAnswer($this->request->criteria['operation'] ?? null), 403);
        $this->optionalQuestionsOpen = true;
    }

    public function closeOptionalQuestions(): void
    {
        $this->optionalQuestionsOpen = false;
    }

    public function openSpecifics(): void
    {
        abort_unless($this->editable && Questionnaire::hasAnswer($this->request->criteria['operation'] ?? null), 403);
        $criteria = $this->request->criteria;
        $this->specificNotes = (string) ($criteria['notes'] ?? '');
        $this->specificTags = array_values((array) ($criteria['tags'] ?? []));
        $this->specificSelected = '';
        $this->specificValue = null;
        $this->resetErrorBag();
        $this->specificsOpen = true;
    }

    public function closeSpecifics(): void
    {
        $this->specificsOpen = false;
        $this->resetErrorBag();
    }

    /** Specifiche manuali: domande fuori dal percorso guidato, senza tag e senza l'approfondimento facoltativo. */
    #[Computed]
    public function specificQuestions(): array
    {
        return array_values(array_filter($this->questionnaire->manual($this->request->criteria),
            fn ($q) => $q['id'] !== 'tags' && ! in_array($q['id'], ProfileFlow::OPTIONAL_IDS, true)));
    }

    public function selectSpecific(string $id): void
    {
        $question = array_column($this->specificQuestions, null, 'id')[$id] ?? null;
        $this->specificSelected = $question['id'] ?? '';
        $this->specificValue = $question ? AnswerPresenter::toField($question, $this->request->criteria[$id] ?? null) : null;
    }

    public function updatedSpecificSelected(string $id): void
    {
        $this->selectSpecific($id);
    }

    public function clearSpecific(): void
    {
        $question = array_column($this->specificQuestions, null, 'id')[$this->specificSelected] ?? null;
        $this->specificValue = $question ? AnswerPresenter::toField($question, null) : null;
    }

    /** "Salva specifiche e note": note, tag e specifica scelta, ognuno con il suo toast. */
    public function saveSpecifics(): void
    {
        abort_unless($this->editable, 403);
        $criteria = $this->request->criteria;
        $steps = [];
        if ($this->specificNotes !== (string) ($criteria['notes'] ?? '')) {
            $steps[] = [['question_id' => 'notes', 'value' => trim($this->specificNotes) ?: null], 'Note della richiesta salvate'];
        }
        if (array_values($this->specificTags) !== array_values((array) ($criteria['tags'] ?? []))) {
            $steps[] = [['question_id' => 'tags', 'value' => $this->specificTags ?: null], 'Tag salvati. Abbinamenti aggiornati.'];
        }
        $question = array_column($this->specificQuestions, null, 'id')[$this->specificSelected] ?? null;
        if ($question) {
            $steps[] = [[
                'question_id' => $question['id'], 'value' => AnswerPresenter::fromField($question, $this->specificValue),
                'classification' => $this->request->classifications[$question['id']] ?? $question['classification'] ?? 'Preferibile',
            ], 'Specifica salvata. Abbinamenti aggiornati.'];
        }
        foreach ($steps as [$input, $message]) {
            $fresh = $this->command(fn () => app(AnswerPropertyRequestQuestion::class)->handle($this->actor(), $this->request,
                [...$input, 'next_step' => $this->request->step_id, 'expected_updated_at' => $this->revision]), $message);
            if (! $fresh) {
                return;
            }
            $this->sync($fresh);
        }
        $this->specificsOpen = false;
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
        unset($this->groups, $this->progress, $this->specificQuestions);
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

    /** Il clic sulla mappa sceglie il punto della ricerca (location-map.tsx): lo salva come risposta. */
    #[On('map-point-picked')]
    public function pickPoint(string $key = '', mixed $lat = null, mixed $lng = null): void
    {
        if (! $this->editable || ! is_numeric($lat) || ! is_numeric($lng) || abs((float) $lat) > 90 || abs((float) $lng) > 180) {
            return;
        }
        $merge = function (mixed $current) use ($lat, $lng): array {
            $current = is_array($current) ? $current : [];

            return [...$current, 'label' => 'Punto scelto sulla mappa', 'lat' => (string) $lat, 'lng' => (string) $lng,
                'radius' => (string) (($current['radius'] ?? '') !== '' ? $current['radius'] : 2)];
        };

        if (str_starts_with($key, 'pick-answers.')) {
            $id = substr($key, strlen('pick-answers.'));
            if (($this->questionnaire->question($id)['type'] ?? null) === 'zone') {
                $this->answers[$id] = $merge($this->answers[$id] ?? null);
                $this->save($id);
            }
        } elseif ($key === 'pick-specificValue' && $this->specificsOpen) {
            $question = array_column($this->specificQuestions, null, 'id')[$this->specificSelected] ?? null;
            if (($question['type'] ?? null) === 'zone') {
                $this->specificValue = $merge($this->specificValue);
            }
        }
    }

    private function save(string $id): void
    {
        $question = $this->questionnaire->question($id);
        if (! $question || ! $this->editable) {
            return;
        }
        $value = AnswerPresenter::fromField($question, $this->answers[$id] ?? null);
        if ($value === ($this->request->criteria[$id] ?? null)) {
            return;
        }
        $this->resetErrorBag();
        try {
            $fresh = app(AnswerPropertyRequestQuestion::class)->handle($this->actor(), $this->request, [
                'question_id' => $id, 'value' => $value,
                'next_step' => $this->review ? 'review' : $this->group, 'expected_updated_at' => $this->revision,
            ]);
            $this->sync($fresh);
            $this->dispatch('crm-notice', type: 'success', text: 'Risposta salvata automaticamente');
        } catch (CommandRejected $e) {
            $this->addError('answers.'.$id, $e->getMessage());
            if ($e->status() === 409) {
                $this->dispatch('crm-notice', type: 'error', text: $e->getMessage());
            }
        }
    }

    public function goStep(string $id): void
    {
        $target = collect($this->groups)->first(fn ($g) => $g['id'] === $id || in_array($id, array_column($g['questions'], 'id'), true))['id']
            ?? ProfileFlow::groupId($this->questionnaire, $this->request->criteria, $id);
        $fresh = $this->command(fn () => app(StepPropertyRequest::class)->handle($this->actor(), $this->request, $target, $this->revision), 'Punto di ripresa salvato');
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

    /** RequestForm (clients.tsx): cambio di cliente e gestione della richiesta. */
    public function manage(): void
    {
        $this->resetErrorBag();
        if ($this->manageContactId === null) {
            $this->addError('manage', trim($this->manageName) === ''
                ? 'Inserisci il nome del cliente.'
                : 'Per cambiare cliente, digita il nome e apri una scheda già presente tra i suggerimenti.');

            return;
        }
        $fresh = $this->command(fn () => app(SavePropertyRequest::class)->handle($this->actor(), [
            'contact_id' => $this->manageContactId, 'status' => $this->manageStatus, 'expected_updated_at' => $this->revision,
        ], $this->request), 'Modifica salvata nell’archivio');
        if ($fresh) {
            $this->sync($fresh->unsetRelation('contact'));
            $this->manageRequestOpen = false;
        }
    }

    /** Azioni di <x-gestionale.crm-lifecycle>: archivia e ripristina sono reversibili, "Rimuovi dalle liste" è del Responsabile. */
    public function archive(int $id): void
    {
        $this->changeLifecycle($id, 'archive', 'Scheda archiviata. Puoi annullare con “Ripristina”.');
    }

    public function restore(int $id): void
    {
        $this->changeLifecycle($id, 'restore', 'Scheda ripristinata');
    }

    public function remove(int $id): void
    {
        $this->changeLifecycle($id, 'remove', 'Scheda rimossa dalla lista. Puoi ripristinarla dagli archiviati.', true);
    }

    private function changeLifecycle(int $id, string $mode, string $success, bool $confirmed = false): void
    {
        abort_unless($id === $this->request->id, 404);
        $done = $this->command(fn () => app(SetRecordLifecycle::class)->handle($this->actor(), $this->request, $mode, $confirmed), $success);
        if ($done) {
            $this->sync($this->request->fresh());
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
    $editable = $this->editable;
@endphp

<div>
    <a class="crm-back" href="{{ route('gestionale.requests.index') }}" wire:navigate><x-gestionale.lucide name="arrow-left" :size="16" />Tutte le richieste</a>
    <div class="crm-page-head">
        <div>
            <p class="proto-eyebrow">REKO Gestionale</p>
            <h1>{{ $request->title }}</h1>
        </div>
        @if ($editable)
            <div class="crm-actions"><button type="button" class="crm-btn secondary" wire:click="openManageRequest"><x-gestionale.lucide name="pencil" :size="16" />Gestisci richiesta</button></div>
        @endif
    </div>

    @if (! $request->finished)
        <button type="button" class="crm-btn secondary" wire:click="selectTab('Profilazione')">Completa dopo · apri il profilo completo</button>
    @endif

    @if (App\Gestionale\Properties\TestRecords::isTestClient($request->contact))
        <p class="crm-contact-warning">TEST · Richiesta collegata a una scheda di prova.</p>
    @endif

    <details class="crm-record-management" wire:ignore.self>
        <summary>Archiviazione della richiesta</summary>
        <x-gestionale.crm-lifecycle kind="request" :id="$request->id" :state="$request->lifecycle_state" :at="$request->lifecycle_at" :admin="$this->actor()->isAdmin()" />
    </details>

    <x-gestionale.record-activities :context="['contact_id' => $request->contact_id, 'property_request_id' => $request->id]" />

    <div class="crm-request-meta">
        <a class="crm-link" href="{{ route('gestionale.clients.show', $request->contact_id) }}" wire:navigate>Apri scheda cliente</a>
    </div>

    <nav class="crm-tabs" aria-label="Scheda richiesta">
        @foreach (['Profilazione', 'Abbinamenti', 'Cronologia'] as $tab)
            <button type="button" wire:click="selectTab('{{ $tab }}')" @if ($activeTab === $tab) aria-current="page" @endif @disabled($tab === 'Abbinamenti' && ! $operationKnown)><x-gestionale.lucide :name="$tab === 'Abbinamenti' ? 'sparkles' : 'clipboard-list'" :size="16" />{{ $tab }}</button>
        @endforeach
    </nav>

    @if ($activeTab === 'Profilazione')
    <section class="crm-profile">
        <div class="crm-profile-top">
            <div><p class="proto-eyebrow">Profilazione rapida</p><h2>Le esigenze del cliente.</h2></div>
            <x-gestionale.crm-pill>{{ $progress['answered'] }}/{{ $progress['total'] }} risposte del percorso base</x-gestionale.crm-pill>
        </div>
        <details class="crm-context-help">
            <summary>Come compilare la richiesta</summary>
            <p>Raccogli ciò che cerca il cliente. Indica metri quadri e camere da letto, non vani catastali. I campi facoltativi possono restare vuoti: negli abbinamenti saranno segnalati i dati da verificare.</p>
        </details>
        <div class="crm-profile-tools">
            <p class="crm-profile-intro">Cinque passaggi per le esigenze del cliente. Situazione abitativa e mutuo sono nell’Approfondimento facoltativo.</p>
            @if ($optional !== [])
                <button type="button" class="crm-btn secondary" wire:click="openOptionalQuestions" wire:loading.attr="disabled" @disabled(! $operationKnown || ! $editable)>Approfondimento facoltativo{{ collect($optional)->contains(fn ($q) => App\Gestionale\Questionnaire\Questionnaire::hasAnswer($criteria[$q['id']] ?? null)) ? ' · dati già presenti' : '' }}</button>
            @endif
        </div>
        <div class="crm-progress" role="progressbar" aria-label="Completezza del percorso base" aria-valuenow="{{ $progress['percent'] }}" aria-valuemin="0" aria-valuemax="100"><span style="width: {{ $progress['percent'] }}%"></span></div>

        @if (! $review)
            <div class="crm-question-nav">
                <button class="crm-link" type="button" wire:click="back" wire:loading.attr="disabled" @disabled($index === 0)><x-gestionale.lucide name="arrow-left" :size="16" />Indietro</button>
                <span>Passo {{ $index + 1 }} di {{ count($groups) }}</span>
                <button class="crm-link" type="button" wire:click="openReview" wire:loading.attr="disabled" @disabled(! $operationKnown)>Riepilogo</button>
            </div>
            @include('gestionale.partials.question-group', ['title' => $current['title'], 'questions' => $current['questions'], 'advance' => $editable ? ($current['id'] === 'notes' ? 'Concludi e vedi il riepilogo' : 'Continua') : null])
            @if ($current['id'] === 'notes' && $editable)
                <div class="crm-finish"><button type="button" class="crm-btn secondary" wire:click="openSpecifics" wire:loading.attr="disabled">Altre specifiche facoltative</button></div>
            @endif
        @else
            @php
                $reviewQuestions = collect($groups)->flatMap(fn ($g) => $g['questions'])->filter(fn ($q) => $q['id'] !== 'tags');
                $missing = $progress['total'] - $progress['answered'];
                $firstMissing = $reviewQuestions->first(fn ($q) => ! App\Gestionale\Questionnaire\Questionnaire::hasAnswer($criteria[$q['id']] ?? null));
            @endphp
            <div class="crm-completion"><x-gestionale.lucide name="check" :size="22" /><div><p>{{ $missing > 0 ? 'Salvato · '.$missing.($missing === 1 ? ' risposta ancora da definire' : ' risposte ancora da definire') : 'Salvato · tutte le risposte del percorso base sono presenti' }}</p>@if ($firstMissing && $editable)<button type="button" class="crm-link" wire:click="goStep('{{ $firstMissing['id'] }}')">Completa le risposte mancanti</button>@endif <small>I campi facoltativi possono restare da definire; gli abbinamenti useranno solo i dati disponibili.</small></div></div>
            <div class="crm-section-head"><h2>Riepilogo delle preferenze</h2></div>
            @if ($editable)
                <div class="crm-actions">
                    <button class="crm-btn secondary" type="button" wire:click="goStep('{{ $groups[0]['id'] }}')"><x-gestionale.lucide name="pencil" :size="15" />Modifica parametri richiesta</button>
                    <button class="crm-link" type="button" wire:click="openSpecifics">Note e tag</button>
                </div>
            @endif
            <x-gestionale.crm-tags :value="$criteria['tags'] ?? null" />
            <div class="crm-review-grid">
                @foreach ($reviewQuestions as $q)
                    <button type="button" @if ($editable) wire:click="goStep('{{ $q['id'] }}')" @endif wire:key="rv-{{ $q['id'] }}">
                        <span>
                            <small>{{ App\Gestionale\Questionnaire\AnswerPresenter::questionLabel($q) }}</small>
                            <strong>{{ App\Gestionale\Questionnaire\AnswerPresenter::answerLabel($q['id'], $criteria[$q['id']] ?? null) }}</strong>
                        </span>
                        <x-gestionale.lucide name="pencil" :size="15" />
                    </button>
                @endforeach
            </div>
            @if (! $request->finished && $editable)
                <button class="crm-btn" type="button" wire:click="finish">Conferma le preferenze disponibili</button>
            @endif
        @endif
        <p class="crm-operator-note">* Per l’operatore: più aiuti il cliente a essere preciso, più la richiesta sarà utile e gli abbinamenti pertinenti.</p>
    </section>

    @elseif ($activeTab === 'Abbinamenti')
    <section class="crm-panel">
        <h2>Immobili da confrontare con la richiesta</h2>
        <p class="crm-muted">Punteggio e attendibilità sono distinti. Un requisito critico resta evidenziato, anche con un punteggio alto.</p>
        <livewire:gestionale.crm-matches :request-id="$request->id" :key="'request-matches-'.$request->id" />
    </section>
    @else
        <section class="crm-panel"><x-gestionale.activity-timeline :activities="\App\Gestionale\Activities\ActivityContext::activities($this->actor(), ['property_request_id' => $request->id])" :scope="\App\Gestionale\Activities\ActivityContext::scope(['contact_id' => $request->contact_id, 'property_request_id' => $request->id])" order="recent" /></section>
    @endif

    @if ($manageRequestOpen)
        @php
            $manageError = $errors->first('manage') ?: ($errors->first('contact_id') ?: ($errors->first('status') ?: $errors->first('command')));
            $suggestions = $this->manageSuggestions;
        @endphp
        <x-gestionale.crm-dialog title="Dati della richiesta" :close="'$wire.closeManageRequest()'">
            <form class="crm-form" wire:submit="manage" novalidate>
                <div class="crm-client-entry" x-data="{ open: false, active: -1, items() { return this.$refs.list ? [...this.$refs.list.children] : [] } }">
                    <label class="crm-field" for="manage-client"><span>Nome e cognome · demo</span>
                        <input id="manage-client" role="combobox" aria-autocomplete="list" aria-controls="manage-client-matches" autocomplete="off" required maxlength="120" placeholder="Digita nome e cognome, telefono o email"
                            wire:model.live.debounce.250ms="manageName"
                            x-bind:aria-expanded="(open && items().length > 0).toString()"
                            x-bind:aria-activedescendant="open && active >= 0 ? 'manage-client-opt-' + active : null"
                            x-on:focus="open = true" x-on:blur="open = false" x-on:input="open = true; active = -1"
                            x-on:keydown.arrow-down.prevent="if (open && items().length) active = Math.min(active + 1, items().length - 1)"
                            x-on:keydown.arrow-up.prevent="if (open && items().length) active = Math.max(active - 1, 0)"
                            x-on:keydown.enter="if (open && active >= 0 && items()[active]) { $event.preventDefault(); items()[active].click() }"
                            x-on:keydown.escape="if (open && items().length) { $event.preventDefault(); $event.stopPropagation(); open = false }">
                    </label>
                    @if ($suggestions->isNotEmpty())
                        <div class="crm-client-suggestions" role="listbox" id="manage-client-matches" aria-label="Clienti già presenti" x-ref="list" x-show="open">
                            @foreach ($suggestions as $client)
                                <button type="button" role="option" id="manage-client-opt-{{ $loop->index }}" wire:key="manage-client-{{ $client->id }}" x-bind:aria-selected="(active === {{ $loop->index }}).toString()" x-on:mousedown.prevent wire:click="pickClient({{ $client->id }})"><strong>{{ $client->display_name }}</strong><small>{{ $client->primaryPhone?->value ?: ($client->primaryEmail?->value ?: 'Scheda già presente') }}</small></button>
                            @endforeach
                        </div>
                    @endif
                    @if ($manageContactId !== null)<small class="crm-success">Cliente già in archivio · nessun doppione</small>@endif
                </div>
                <div class="crm-form-grid">
                    <label class="crm-field"><span>Cellulare / telefono</span><input type="tel" wire:model="managePhone" @readonly($manageContactId !== null) autocomplete="off" placeholder="+39 000 000 0000"></label>
                    <label class="crm-field"><span>Email</span><input type="email" wire:model="manageEmail" @readonly($manageContactId !== null) autocomplete="off" placeholder="cliente@example.invalid"></label>
                </div>
                @if ($manageContactId !== null)<p class="crm-muted">I recapiti sono quelli della scheda cliente e non vengono duplicati.</p>@endif
                <details wire:ignore.self><summary>Gestione della richiesta</summary>
                    <label class="crm-field"><span>Stato della richiesta</span><select wire:model="manageStatus">@foreach (App\Models\PropertyRequest::STATUSES as $s)<option value="{{ $s }}">{{ $s }}</option>@endforeach</select></label>
                </details>
                <p class="crm-muted">Usa dati di fantasia. Nome della richiesta e stato iniziale vengono impostati automaticamente.</p>
                @if ($manageError)<p class="crm-error" role="alert">{{ $manageError }}</p>@endif
                <div class="crm-form-footer"><button class="crm-btn" wire:loading.attr="disabled" wire:target="manage"><span wire:loading.remove wire:target="manage">Salva collegamento</span><span wire:loading wire:target="manage">Salvataggio…</span></button></div>
            </form>
        </x-gestionale.crm-dialog>
    @endif

    @if ($optionalQuestionsOpen)
        <x-gestionale.crm-dialog title="Approfondimento facoltativo" wide :close="'$wire.closeOptionalQuestions()'">
            <p class="crm-info">Situazione abitativa e mutuo: raccogli solo ciò che serve alla pratica. Queste informazioni non entrano nel punteggio.</p>
            @include('gestionale.partials.question-group', ['title' => 'Situazione abitativa e mutuo', 'questions' => $optional, 'advance' => null])
            <div class="crm-form-footer"><button type="button" class="crm-btn" wire:click="closeOptionalQuestions" wire:loading.attr="disabled">Torna alla richiesta</button></div>
        </x-gestionale.crm-dialog>
    @endif

    @if ($specificsOpen)
        @php
            $specificQuestions = $this->specificQuestions;
            $savedSpecifics = array_filter($specificQuestions, fn ($q) => App\Gestionale\Questionnaire\Questionnaire::hasAnswer($criteria[$q['id']] ?? null));
            $selectedSpecific = array_column($specificQuestions, null, 'id')[$specificSelected] ?? null;
            $archived = array_values(array_filter($this->questionnaire->questions(),
                fn ($q) => ($q['collection'] ?? '') === 'archived' && App\Gestionale\Questionnaire\Questionnaire::hasAnswer($criteria[$q['id']] ?? null)));
            $specificError = $errors->first('command') ?: $errors->first('value');
        @endphp
        <x-gestionale.crm-dialog title="Note, tag e specifiche" wide :close="'$wire.closeSpecifics()'">
            <div class="crm-form">
                <label class="crm-field"><span>Note della richiesta</span><textarea wire:model="specificNotes" maxlength="4000" placeholder="Scrivi ciò che conta per questa ricerca…"></textarea></label>
                <x-gestionale.crm-tag-input model="specificTags" :value="$specificTags" label="Tag della richiesta" :live="false" />
                <p class="crm-muted">I tag si confrontano con caratteristiche e descrizione della scheda immobile. Puoi aggiungere altre specifiche solo quando servono.</p>
                @if ($savedSpecifics !== [])
                    <div><h3>Specifiche già raccolte</h3>
                        <div class="crm-review-grid">
                            @foreach ($savedSpecifics as $q)
                                <button type="button" wire:key="saved-{{ $q['id'] }}" wire:click="selectSpecific('{{ $q['id'] }}')"><span><small>{{ App\Gestionale\Questionnaire\AnswerPresenter::questionLabel($q) }}</small><strong>{{ App\Gestionale\Questionnaire\AnswerPresenter::answerLabel($q['id'], $criteria[$q['id']]) }}</strong></span><x-gestionale.lucide name="pencil" :size="15" /></button>
                            @endforeach
                        </div>
                    </div>
                @endif
                <label class="crm-field"><span>Aggiungi o modifica una specifica</span><select wire:model.live="specificSelected"><option value="">Scegli solo ciò che serve…</option>@foreach ($specificQuestions as $q)<option value="{{ $q['id'] }}">{{ App\Gestionale\Questionnaire\AnswerPresenter::questionLabel($q) }}</option>@endforeach</select></label>
                @if ($selectedSpecific)
                    <section class="crm-manual-answer" wire:key="specific-{{ $selectedSpecific['id'] }}"><h3>{{ App\Gestionale\Questionnaire\AnswerPresenter::questionLabel($selectedSpecific) }}</h3>
                        @if (! empty($selectedSpecific['explanation']))<p class="crm-muted">{{ $selectedSpecific['explanation'] }}</p>@endif
                        <x-gestionale.answer-input :question="$selectedSpecific" :options="$this->questionnaire->options($selectedSpecific, $criteria)" model="specificValue" wm="wire:model" :value="$specificValue" />
                        <button type="button" class="crm-link" wire:click="clearSpecific">Rimuovi questa specifica</button>
                    </section>
                @endif
                @if ($archived !== [])
                    <details wire:ignore.self><summary>Informazioni raccolte in precedenza</summary><p class="crm-muted">Conservate nello storico, escluse dal percorso rapido e dagli abbinamenti.</p>@foreach ($archived as $q)<p><strong>{{ App\Gestionale\Questionnaire\AnswerPresenter::questionLabel($q) }}</strong><br>{{ App\Gestionale\Questionnaire\AnswerPresenter::answerLabel($q['id'], $criteria[$q['id']]) }}</p>@endforeach</details>
                @endif
                @if ($specificError)<p class="crm-error" role="alert">{{ $specificError }}</p>@endif
                <div class="crm-form-footer"><button type="button" class="crm-btn" wire:click="saveSpecifics" wire:loading.attr="disabled" wire:target="saveSpecifics">Salva specifiche e note</button></div>
            </div>
        </x-gestionale.crm-dialog>
    @endif
</div>
