<?php

use App\Gestionale\Actions\Requests\CreatePropertyRequest;
use App\Gestionale\Actions\Requests\SavePropertyRequest;
use App\Gestionale\Clients\ClientMatcher;
use App\Gestionale\Livewire\HandlesCommands;
use App\Gestionale\Questionnaire\AnswerPresenter;
use App\Gestionale\Questionnaire\Questionnaire;
use App\Gestionale\Questionnaire\QuickRequestCriteria;
use App\Models\Contact;
use App\Models\PropertyRequest;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * Nuova richiesta (quick-request-form.tsx): cliente esistente o nuovo, contratto, tipologia, zona,
 * budget e dettagli facoltativi. Una sola richiesta per cliente. Tutte le regole sono nell'Action
 * CreatePropertyRequest (request.create) e in QuickRequestCriteria.
 */
new #[Layout('layouts::gestionale'), Title('Nuova richiesta')] class extends Component {
    use HandlesCommands;

    #[Url(as: 'cliente')]
    public ?int $contactId = null;

    public string $clientName = '';

    public string $phone = '';

    public string $email = '';

    public bool $confirmHomonym = false;

    public string $operation = '';

    public string $typology = '';

    public string $zone = '';

    public string $budgetMin = '';

    public string $budgetMax = '';

    public string $notes = '';

    public bool $showDetails = false;

    /** @var array<string, mixed> raw detail fields keyed by question id */
    public array $details = [];

    public string $idempotencyKey = '';

    public function mount(): void
    {
        $this->authorize('create', PropertyRequest::class);
        if ($this->contactId) {
            $contact = Contact::query()->visibleTo($this->actor())->whereHas('clientProfile')->find($this->contactId);
            $this->contactId = $contact?->id;
            $this->clientName = (string) $contact?->display_name;
        }
        $this->idempotencyKey = (string) str()->uuid();
    }

    #[Computed]
    public function questionnaire(): Questionnaire
    {
        return Questionnaire::forAgency((int) $this->actor()->agency_id);
    }

    #[Computed]
    public function selected(): ?Contact
    {
        return $this->contactId ? Contact::query()->visibleTo($this->actor())->find($this->contactId) : null;
    }

    /** Suggestions from 2 characters, at most 6, among the clients the operator can see. */
    #[Computed]
    public function suggestions()
    {
        $term = trim($this->clientName);
        if ($this->contactId || mb_strlen($term) < 2) {
            return collect();
        }
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';

        return Contact::query()->visibleTo($this->actor())->whereHas('clientProfile', fn ($p) => $p->activeRecords())
            ->where(fn ($q) => $q->where('display_name', 'ilike', $like)->orWhereHas('channels', fn ($c) => $c->where('value', 'ilike', $like)))
            ->orderBy('display_name')->limit(6)->get();
    }

    /** Hints for a new client: existing contacts or homonyms (names only of visible clients). */
    #[Computed]
    public function matches()
    {
        if ($this->contactId || trim($this->clientName) === '') {
            return collect();
        }

        return ClientMatcher::matches($this->clientName, $this->phone, $this->email);
    }

    #[Computed]
    public function existing(): ?PropertyRequest
    {
        return $this->selected ? SavePropertyRequest::existingFor($this->selected)->first() : null;
    }

    #[Computed]
    public function purposeCriteria(): array
    {
        $purpose = $this->details['purpose'] ?? '';

        return array_filter(['operation' => $this->operation ?: null, 'purpose' => $purpose ?: null]);
    }

    #[Computed]
    public function typologies(): array
    {
        return isset($this->purposeCriteria['purpose']) ? Questionnaire::typologies($this->purposeCriteria) : Questionnaire::quickTypologies();
    }

    #[Computed]
    public function detailQuestions(): array
    {
        return QuickRequestCriteria::detailQuestions($this->questionnaire, $this->purposeCriteria);
    }

    public function choose(int $id): void
    {
        $contact = Contact::query()->visibleTo($this->actor())->whereHas('clientProfile')->findOrFail($id);
        $this->contactId = $contact->id;
        $this->clientName = $contact->display_name;
        $this->reset('phone', 'email', 'confirmHomonym');
    }

    public function clearClient(): void
    {
        $this->reset('contactId', 'clientName', 'phone', 'email', 'confirmHomonym');
    }

    /** A name matching exactly one visible client, with no contact typed, links that client. */
    public function updatedClientName(): void
    {
        if ($this->contactId || $this->phone !== '' || $this->email !== '') {
            return;
        }
        $named = $this->matches->filter(fn ($m) => $m->name);
        if ($named->count() === 1 && auth()->user()->can('update', $named->first()->contact)) {
            $this->choose($named->first()->contact->id);
        }
    }

    public function save()
    {
        $num = fn (string $v) => ($v = str_replace([' ', '.', ','], ['', '', '.'], trim($v))) === '' ? null : (is_numeric($v) ? $v + 0 : $v);
        $details = [];
        foreach ($this->detailQuestions as $question) {
            $value = AnswerPresenter::fromField($question, $this->details[$question['id']] ?? null);
            if (Questionnaire::hasAnswer($value)) {
                $details[$question['id']] = $value;
            }
        }
        $quick = array_filter([
            'operation' => $this->operation, 'typology' => $this->typology, 'zone' => $this->zone,
            'budgetMin' => $num($this->budgetMin), 'budgetMax' => $num($this->budgetMax),
            'notes' => trim($this->notes) === '' ? null : $this->notes,
            'details' => $details ?: null,
        ], fn ($v) => $v !== null);

        $input = ['quick' => $quick, 'idempotency_key' => $this->idempotencyKey];
        if ($this->contactId) {
            $input['contact_id'] = $this->contactId;
        } else {
            $input['client'] = ['name' => $this->clientName, 'phone' => $this->phone, 'email' => $this->email];
            $input['confirm_homonym'] = $this->confirmHomonym;
        }

        $request = $this->command(fn () => app(CreatePropertyRequest::class)->handle($this->actor(), $input),
            trim($this->notes) !== '' || $details ? 'Richiesta salvata con note e dettagli.' : 'Richiesta salvata.');
        if ($request) {
            return $this->redirectRoute('gestionale.requests.show', $request, navigate: true);
        }
        $this->idempotencyKey = (string) str()->uuid();
    }
}; ?>

@php($zonesConfigured = $this->questionnaire->zones())
<div class="mx-auto flex max-w-3xl flex-col gap-6">
    <div>
        <flux:link :href="route('gestionale.requests.index')" wire:navigate>← Richieste</flux:link>
        <flux:heading size="xl" level="1" class="mt-2">Nuova richiesta{{ $this->selected ? ' · '.$this->selected->display_name : '' }}</flux:heading>
    </div>

    <form wire:submit="save" class="flex flex-col gap-5">
        @error('command') <flux:callout variant="danger" icon="exclamation-triangle" :heading="$message" /> @enderror
        @error('client') <flux:callout variant="danger" icon="exclamation-triangle" :heading="$message" /> @enderror
        @error('contact_id') <flux:callout variant="danger" icon="exclamation-triangle" :heading="$message" /> @enderror
        @error('quick') <flux:callout variant="danger" icon="exclamation-triangle" :heading="$message" /> @enderror
        @error('value') <flux:callout variant="danger" icon="exclamation-triangle" :heading="$message" /> @enderror

        <flux:card class="flex flex-col gap-3">
            <flux:heading>Cliente</flux:heading>
            @if ($this->selected)
                <div class="flex items-center gap-3">
                    <flux:avatar :name="$this->selected->display_name" size="sm" />
                    <div class="flex-1">
                        <flux:text class="font-medium">{{ $this->selected->display_name }}</flux:text>
                        <flux:text size="sm">Cliente già in archivio · nessun doppione</flux:text>
                    </div>
                    <flux:button size="sm" variant="ghost" wire:click="clearClient">Cambia</flux:button>
                </div>
                @if ($this->existing)
                    <flux:callout variant="warning" icon="exclamation-triangle">
                        <flux:callout.heading>Il cliente ha già una richiesta.</flux:callout.heading>
                        @can('view', $this->existing)
                            <flux:callout.link :href="route('gestionale.requests.show', $this->existing)">Apri {{ $this->existing->title }}</flux:callout.link>
                        @endcan
                    </flux:callout>
                @endif
            @else
                <flux:input wire:model.live.debounce.300ms="clientName" label="Nome e cognome" placeholder="Cerca un cliente o scrivi il nome di uno nuovo" autocomplete="off" />
                @if ($this->suggestions->isNotEmpty())
                    <div class="divide-y divide-zinc-200 rounded-lg border border-zinc-200">
                        @foreach ($this->suggestions as $s)
                            <button type="button" class="block w-full p-2 text-start hover:bg-zinc-50" wire:click="choose({{ $s->id }})" wire:key="s-{{ $s->id }}">{{ $s->display_name }}</button>
                        @endforeach
                    </div>
                @endif
                <div class="grid gap-4 md:grid-cols-2">
                    <flux:input wire:model.live.debounce.400ms="phone" label="Cellulare" type="tel" maxlength="60" />
                    <flux:input wire:model.live.debounce.400ms="email" label="Email" type="email" maxlength="160" />
                </div>
                @foreach ($this->matches as $m)
                    @if ($m->phone || $m->email)
                        <flux:text size="sm" class="text-amber-700">
                            @can('update', $m->contact)
                                Recapito già presente: <flux:link as="button" wire:click="choose({{ $m->contact->id }})">Usa {{ $m->contact->display_name }}</flux:link>
                            @else
                                Recapito già presente in un’altra scheda dell’agenzia: verifica con il Responsabile.
                            @endcan
                        </flux:text>
                    @endif
                @endforeach
                @if ($this->matches->contains(fn ($m) => $m->name && ! $m->phone && ! $m->email))
                    <flux:checkbox wire:model="confirmHomonym" label="Ho verificato che è una persona diversa dal cliente omonimo." />
                @endif
            @endif
        </flux:card>

        <flux:card class="flex flex-col gap-4">
            <flux:radio.group wire:model.live="operation" label="Acquisto o locazione" variant="segmented">
                <flux:radio value="Acquisto" label="Acquisto" />
                <flux:radio value="Locazione" label="Locazione" />
            </flux:radio.group>
            <flux:select wire:model="typology" label="Tipologia" placeholder="Scegli la tipologia">
                @foreach ($this->typologies as $t)
                    <flux:select.option :value="$t">{{ $t }}</flux:select.option>
                @endforeach
            </flux:select>
            <div>
                <flux:input wire:model.live.debounce.400ms="zone" label="Zona o Comune" list="gestionale-zones" maxlength="200" />
                <datalist id="gestionale-zones">
                    @foreach ($zonesConfigured as $z)
                        <option value="{{ $z }}"></option>
                    @endforeach
                </datalist>
                @if (trim($zone) !== '' && ! collect($zonesConfigured)->contains(fn ($z) => mb_strtolower(trim($z)) === mb_strtolower(trim($zone))))
                    <flux:text size="sm" class="mt-1">La zona sarà annotata nelle note della richiesta, da precisare nella profilazione.</flux:text>
                @endif
            </div>
            <div class="grid grid-cols-2 gap-4">
                <flux:input wire:model="budgetMin" :label="$operation === 'Locazione' ? 'Canone · €/mese · Da' : 'Budget · € · Da'" inputmode="decimal" />
                <flux:input wire:model="budgetMax" :label="$operation === 'Locazione' ? 'Canone · €/mese · A' : 'Budget · € · A'" inputmode="decimal" />
            </div>
            <flux:textarea wire:model="notes" label="Note libere" rows="3" maxlength="4000" />

            <div>
                <flux:button variant="ghost" size="sm" type="button" :icon="$showDetails ? 'chevron-down' : 'chevron-right'" wire:click="$toggle('showDetails')">Aggiungi dettagli</flux:button>
            </div>
            @if ($showDetails)
                @foreach ($this->detailQuestions as $question)
                    <flux:field wire:key="d-{{ $question['id'] }}">
                        <flux:label>{{ App\Gestionale\Questionnaire\AnswerPresenter::questionLabel($question) }}</flux:label>
                        <x-gestionale.answer-input :question="$question" :options="$this->questionnaire->options($question, $this->purposeCriteria)" model="details.{{ $question['id'] }}" />
                        <flux:text size="sm">Lascia vuoto se da definire.</flux:text>
                    </flux:field>
                @endforeach
            @endif
        </flux:card>

        <div class="flex gap-2">
            <flux:button type="submit" variant="primary" :disabled="(bool) $this->existing">Salva richiesta</flux:button>
            <flux:button :href="route('gestionale.requests.index')" variant="ghost" wire:navigate>Annulla</flux:button>
        </div>
    </form>
</div>
