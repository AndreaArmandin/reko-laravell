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
use Livewire\Attributes\On;
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

    #[On('map-point-picked')]
    public function pickPoint(string $key = '', mixed $lat = null, mixed $lng = null): void
    {
        if (! str_starts_with($key, 'pick-details.') || ! is_numeric($lat) || ! is_numeric($lng)
            || abs((float) $lat) > 90 || abs((float) $lng) > 180) {
            return;
        }

        $id = substr($key, strlen('pick-details.'));
        if (($this->questionnaire->question($id)['type'] ?? null) !== 'zone') {
            return;
        }

        $point = is_array($this->details[$id] ?? null) ? $this->details[$id] : [];
        $this->details[$id] = [
            ...$point,
            'label' => trim((string) ($point['label'] ?? '')) ?: 'Punto scelto',
            'lat' => (string) $lat,
            'lng' => (string) $lng,
            'radius' => (string) ($point['radius'] ?? '2'),
        ];
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
<div class="crm-form-page crm-form-page--request">
    <a class="crm-back" href="{{ route('gestionale.requests.index') }}" wire:navigate>← Richieste</a>
    <div class="crm-page-head"><div><p class="proto-eyebrow">REKO Gestionale · Richieste</p><h1>Nuova richiesta{{ $this->selected ? ' · '.$this->selected->display_name : '' }}</h1><p class="crm-muted">Registra la ricerca del cliente. Puoi completare il profilo guidato anche in seguito.</p></div></div>

    <form wire:submit="save" class="crm-form">
        @foreach (['command', 'client', 'contact_id', 'quick', 'value'] as $errorKey)
            @error($errorKey)<p class="crm-error" role="alert">{{ $message }}</p>@enderror
        @endforeach

        <section class="crm-panel">
            <div class="crm-section-head"><h2>Cliente</h2>@if ($this->selected)<button type="button" class="crm-btn secondary" wire:click="clearClient">Cambia cliente</button>@endif</div>
            @if ($this->selected)
                <div class="crm-record-row"><x-gestionale.crm-avatar :name="$this->selected->display_name" :id="$this->selected->id"/><span class="crm-grow"><strong>{{ $this->selected->display_name }}</strong><small>Cliente già in archivio · nessun doppione</small></span></div>
                @if ($this->existing)<p class="crm-contact-warning">Il cliente ha già una richiesta. @can('view', $this->existing)<a class="crm-link" href="{{ route('gestionale.requests.show', $this->existing) }}" wire:navigate>Apri {{ $this->existing->title }}</a>@endcan</p>@endif
            @else
                <label class="crm-field"><span>Nome e cognome</span><input wire:model.live.debounce.300ms="clientName" placeholder="Cerca un cliente o scrivi il nome di uno nuovo" autocomplete="off"></label>
                @if ($this->suggestions->isNotEmpty())<div class="crm-client-suggestions">@foreach ($this->suggestions as $s)<button type="button" wire:click="choose({{ $s->id }})" wire:key="s-{{ $s->id }}">{{ $s->display_name }}</button>@endforeach</div>@endif
                <div class="crm-form-grid">
                    <label class="crm-field"><span>Cellulare</span><input wire:model.live.debounce.400ms="phone" type="tel" maxlength="60" autocomplete="off" placeholder="+39 000 000 0000"></label>
                    <label class="crm-field"><span>Email</span><input wire:model.live.debounce.400ms="email" type="email" maxlength="160" autocomplete="off" placeholder="cliente@example.invalid"></label>
                </div>
                @foreach ($this->matches as $m)
                    @if ($m->phone || $m->email)
                        <p class="crm-contact-warning">@can('update', $m->contact)Recapito già presente: <button class="crm-link" type="button" wire:click="choose({{ $m->contact->id }})">Usa {{ $m->contact->display_name }}</button>@else Recapito già presente in un’altra scheda dell’agenzia: verifica con il Responsabile.@endcan</p>
                    @endif
                @endforeach
                @if ($this->matches->contains(fn ($m) => $m->name && ! $m->phone && ! $m->email))<label class="crm-check"><input type="checkbox" wire:model="confirmHomonym">Ho verificato che è una persona diversa dal cliente omonimo.</label>@endif
            @endif
        </section>

        <section class="crm-panel">
            <h2>Esigenze principali</h2>
            <div class="crm-form" style="margin-top:16px">
                <fieldset class="crm-fieldset"><legend>Acquisto o locazione</legend><div class="crm-filter-chips" role="group" aria-label="Acquisto o locazione">
                    @foreach (['Acquisto', 'Locazione'] as $value)<button type="button" class="crm-chip" aria-pressed="{{ $operation === $value ? 'true' : 'false' }}" wire:click="$set('operation', '{{ $value }}')">{{ $value }}</button>@endforeach
                </div></fieldset>
                <label class="crm-field"><span>Tipologia</span><select wire:model="typology"><option value="">Scegli la tipologia</option>@foreach ($this->typologies as $t)<option value="{{ $t }}">{{ $t }}</option>@endforeach</select></label>
                <label class="crm-field"><span>Zona o Comune</span><input wire:model.live.debounce.400ms="zone" list="gestionale-zones" maxlength="200"><datalist id="gestionale-zones">@foreach ($zonesConfigured as $z)<option value="{{ $z }}"></option>@endforeach</datalist></label>
                @if (trim($zone) !== '' && ! collect($zonesConfigured)->contains(fn ($z) => mb_strtolower(trim($z)) === mb_strtolower(trim($zone)))<p class="crm-muted">La zona sarà annotata nelle note della richiesta, da precisare nella profilazione.</p>@endif
                <div class="crm-form-grid"><label class="crm-field"><span>{{ $operation === 'Locazione' ? 'Canone · €/mese · Da' : 'Budget · € · Da' }}</span><input wire:model="budgetMin" inputmode="decimal"></label><label class="crm-field"><span>{{ $operation === 'Locazione' ? 'Canone · €/mese · A' : 'Budget · € · A' }}</span><input wire:model="budgetMax" inputmode="decimal"></label></div>
                <label class="crm-field"><span>Note libere</span><textarea wire:model="notes" rows="3" maxlength="4000"></textarea></label>
                <button class="crm-link" type="button" wire:click="$toggle('showDetails')">{{ $showDetails ? '⌄ Nascondi dettagli' : '› Aggiungi dettagli' }}</button>
                @if ($showDetails)
                    @foreach ($this->detailQuestions as $question)
                        <fieldset class="crm-fieldset" wire:key="d-{{ $question['id'] }}"><legend>{{ App\Gestionale\Questionnaire\AnswerPresenter::questionLabel($question) }}</legend>
                            <x-gestionale.answer-input :question="$question" :options="$this->questionnaire->options($question, $this->purposeCriteria)" model="details.{{ $question['id'] }}" />
                            <small>Lascia vuoto se da definire.</small>
                        </fieldset>
                    @endforeach
                @endif
            </div>
        </section>
        <div class="crm-form-footer"><button class="crm-btn" type="submit" @disabled((bool) $this->existing) wire:loading.attr="disabled" wire:target="save"><span wire:loading.remove wire:target="save">Salva richiesta</span><span wire:loading wire:target="save">Salvataggio…</span></button><a class="crm-btn secondary" href="{{ route('gestionale.requests.index') }}" wire:navigate>Annulla</a></div>
    </form>
</div>
