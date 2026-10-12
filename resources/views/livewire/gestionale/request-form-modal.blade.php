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
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * Richiesta rapida (quick-request-form.tsx): finestra ampia aperta sopra la lista Richieste o la scheda cliente.
 * Cliente esistente o nuovo, contratto, tipologia, zona, budget e dettagli facoltativi. Una sola richiesta per cliente.
 * Tutte le regole sono nell'Action CreatePropertyRequest (request.create) e in QuickRequestCriteria.
 */
new class extends Component {
    use HandlesCommands;

    public bool $open = false;

    public bool $routed = false;

    #[Url(as: 'cliente')]
    public ?int $contactId = null;

    /** Il cliente con cui la finestra è stata aperta (initialId): titolo e destinazione alla chiusura. */
    public ?int $originId = null;

    public string $heading = 'Nuova richiesta';

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

    /** @var array<string, mixed> raw detail fields keyed by question id */
    public array $details = [];

    public string $idempotencyKey = '';

    public function mount(?string $autoOpen = null): void
    {
        if ($autoOpen !== null) {
            $contactId = ctype_digit($autoOpen) && (int) $autoOpen > 0 ? (int) $autoOpen : null;
            $this->openForm($contactId);
            $this->routed = true;
        }
    }

    #[On('open-request-form')]
    public function openForm(?int $contactId = null): void
    {
        $this->authorize('create', PropertyRequest::class);
        $this->resetValidation();
        $this->resetErrorBag();
        $this->reset('contactId', 'originId', 'heading', 'clientName', 'phone', 'email', 'confirmHomonym', 'operation', 'typology', 'zone', 'budgetMin', 'budgetMax', 'notes', 'details');
        $this->routed = false;
        if ($contactId !== null) {
            $contact = Contact::query()->visibleTo($this->actor())->whereHas('clientProfile')->find($contactId);
            if ($contact !== null) {
                $this->contactId = $contact->id;
                $this->clientName = (string) $contact->display_name;
                $this->originId = $contact->id;
                $this->heading = 'Nuova richiesta · '.$contact->display_name;
            }
        }
        $this->idempotencyKey = (string) str()->uuid();
        $this->open = true;
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

    /** client-entry.tsx: suggerimenti da 2 caratteri, al massimo 6, tra i clienti che l'operatore vede. */
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
            ->with(['primaryPhone', 'primaryEmail'])->orderBy('display_name')->limit(6)->get();
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

    /** inferredClient: un solo omonimo e nessun recapito digitato, la richiesta si collega a quella scheda. */
    #[Computed]
    public function inferred(): ?Contact
    {
        if ($this->contactId || trim($this->phone) !== '' || trim($this->email) !== '') {
            return null;
        }
        $named = $this->matches->filter(fn ($m) => $m->name);

        return $named->count() === 1 && auth()->user()->can('update', $named->first()->contact) ? $named->first()->contact : null;
    }

    /** existingRequests() del cliente scelto o dedotto. */
    #[Computed]
    public function existing(): ?PropertyRequest
    {
        $client = $this->selected ?? $this->inferred;

        return $client ? SavePropertyRequest::existingFor($client)->first() : null;
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
        $this->resetErrorBag();
    }

    /** onType: scrivere un altro nome scollega il cliente scelto. */
    public function updatedClientName(): void
    {
        $this->resetErrorBag();
        $this->confirmHomonym = false;
        if ($this->contactId && trim($this->clientName) !== $this->selected?->display_name) {
            $this->contactId = null;
        }
    }

    /** updateDetail(): cambiando la finalità, una tipologia non più compatibile torna da scegliere. */
    public function updatedDetails(mixed $value, string $key): void
    {
        if ($key === 'purpose' && Questionnaire::hasAnswer($value)
            && ! in_array($this->typology, Questionnaire::typologies(['purpose' => $value]), true)) {
            $this->typology = '';
        }
    }

    /** «Lascia da definire»: toglie la risposta del dettaglio. */
    public function clearDetail(string $id): void
    {
        unset($this->details[$id]);
    }

    /** RangeInput: il campo number invia decimali con il punto; «350.000» resta un importo con il separatore delle migliaia. */
    private function amount(string $value): int|float|string|null
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $value = preg_match('/^\d+\.\d{1,2}$/', $value) ? $value : str_replace([' ', '.', ','], ['', '', '.'], $value);

        return is_numeric($value) ? $value + 0 : $value;
    }

    /** Chiudere la finestra riporta alla scheda cliente da cui si è partiti, altrimenti alla lista Richieste. */
    public function close()
    {
        $this->open = false;
        if (! $this->routed) {
            return;
        }

        return $this->originId
            ? $this->redirectRoute('gestionale.clients.show', $this->originId, navigate: true)
            : $this->redirectRoute('gestionale.requests.index', navigate: true);
    }

    public function save()
    {
        $details = [];
        foreach ($this->detailQuestions as $question) {
            $value = AnswerPresenter::fromField($question, $this->details[$question['id']] ?? null);
            if (Questionnaire::hasAnswer($value)) {
                $details[$question['id']] = $value;
            }
        }
        $quick = array_filter([
            'operation' => $this->operation, 'typology' => $this->typology, 'zone' => $this->zone,
            'budgetMin' => $this->amount($this->budgetMin), 'budgetMax' => $this->amount($this->budgetMax),
            'notes' => trim($this->notes) === '' ? null : $this->notes,
            'details' => $details ?: null,
        ], fn ($v) => $v !== null);

        $input = ['quick' => $quick, 'idempotency_key' => $this->idempotencyKey];
        $resolved = $this->selected ?? $this->inferred;
        if ($resolved) {
            $input['contact_id'] = $resolved->id;
        } else {
            $input['client'] = ['name' => $this->clientName, 'phone' => $this->phone, 'email' => $this->email];
            $input['confirm_homonym'] = $this->confirmHomonym;
        }

        $request = $this->command(fn () => app(CreatePropertyRequest::class)->handle($this->actor(), $input), 'Richiesta salvata con note e dettagli.');
        if ($request) {
            return $this->redirectRoute('gestionale.requests.index', navigate: true);
        }
        $this->idempotencyKey = (string) str()->uuid();
    }
}; ?>

@php
    $zonesConfigured = $this->questionnaire->zones();
    $zoneText = trim($zone);
    $zoneConfigured = collect($zonesConfigured)->contains(fn ($z) => mb_strtolower(trim($z)) === mb_strtolower($zoneText));
    $notesMax = $zoneText !== '' && ! $zoneConfigured ? max(0, 4000 - mb_strlen('Zona richiesta: '.$zoneText.' (da precisare nella profilazione).') - 2) : 4000;
    $suggestions = $this->suggestions;
    $count = $suggestions->count();
    $resolved = $this->selected ?? $this->inferred;
    $duplicate = $this->matches->first(fn ($m) => $m->phone || $m->email);
    $homonym = $this->matches->contains(fn ($m) => $m->name);
    $fieldError = fn ($key) => $errors->first($key);
@endphp
<div>
    @if ($open)
    <x-gestionale.crm-dialog :title="$heading" :wide="true" :close="'$wire.close()'">
        <form wire:submit="save" class="crm-form crm-quick-request-form">
            <div class="crm-client-entry" x-data="{ open: false, active: -1 }">
                <label class="crm-field" for="quick-client"><span>Cliente</span>
                    <input id="quick-client" role="combobox" aria-autocomplete="list" aria-expanded="false" x-bind:aria-expanded="open && {{ $count }} > 0" aria-controls="quick-client-matches"
                        x-bind:aria-activedescendant="open && active >= 0 && active < {{ $count }} ? 'quick-client-matches-' + active : null"
                        autocomplete="off" required maxlength="120" placeholder="Digita nome e cognome, telefono o email" wire:model.live.debounce.300ms="clientName"
                        x-on:input="open = true; active = -1" x-on:focus="open = true" x-on:blur="open = false"
                        x-on:keydown.arrow-down="if (open && {{ $count }} > 0) { $event.preventDefault(); active = Math.min(active + 1, {{ $count }} - 1) }"
                        x-on:keydown.arrow-up="if (open && {{ $count }} > 0) { $event.preventDefault(); active = Math.max(active - 1, 0) }"
                        x-on:keydown.enter="if (open && {{ $count }} > 0 && active >= 0) { $event.preventDefault(); $refs.matches.children[active].click() }"
                        x-on:keydown.escape="if (open && {{ $count }} > 0) { $event.preventDefault(); $event.stopPropagation(); open = false }">
                </label>
                @if ($count > 0)
                    <div class="crm-client-suggestions" role="listbox" id="quick-client-matches" aria-label="Clienti già presenti" x-ref="matches" x-show="open">
                        @foreach ($suggestions as $s)
                            <button type="button" role="option" aria-selected="false" x-bind:aria-selected="active === {{ $loop->index }}" id="quick-client-matches-{{ $loop->index }}" wire:key="s-{{ $s->id }}"
                                x-on:mousedown.prevent x-on:click="open = false; active = -1" wire:click="choose({{ $s->id }})"><strong>{{ $s->display_name }}</strong><small>{{ $s->primaryPhone?->value ?: ($s->primaryEmail?->value ?: 'Scheda già presente') }}</small></button>
                        @endforeach
                    </div>
                @endif
                @if ($this->selected)<small class="crm-success">Cliente già in archivio · nessun doppione</small>@endif
                @if ($fieldError('name'))<small class="crm-field-error">{{ $fieldError('name') }}</small>@endif
            </div>

            @if (! $this->selected && trim($clientName) !== '')
                <section class="crm-quick-new-client">
                    <strong>{{ $this->inferred ? 'Cliente già presente' : 'Nuovo cliente' }}</strong>
                    <p>{{ $this->inferred ? 'La richiesta sarà collegata alla scheda di '.$this->inferred->display_name.'. Se è una persona diversa, indica i suoi recapiti qui sotto.' : 'Puoi creare la scheda e salvare la richiesta insieme. Indica almeno un recapito.' }}</p>
                    <div class="crm-form-grid">
                        <label class="crm-field"><span>Telefono</span><input type="tel" maxlength="60" autocomplete="tel" wire:model.live.debounce.400ms="phone">@if ($fieldError('phone'))<small class="crm-field-error">{{ $fieldError('phone') }}</small>@endif</label>
                        <label class="crm-field"><span>Email</span><input type="email" maxlength="160" autocomplete="email" wire:model.live.debounce.400ms="email">@if ($fieldError('email'))<small class="crm-field-error">{{ $fieldError('email') }}</small>@endif</label>
                    </div>
                    @if ($duplicate)
                        <p class="crm-error">Recapito già presente: @can('update', $duplicate->contact)<button type="button" class="crm-link" wire:click="choose({{ $duplicate->contact->id }})">Usa {{ $duplicate->contact->display_name }}</button>@else in un’altra scheda dell’agenzia, verifica con il Responsabile.@endcan</p>
                    @endif
                    @if ($homonym && ! $duplicate && ! $this->inferred)
                        <label class="crm-check"><input type="checkbox" wire:model="confirmHomonym">Ho verificato che è una persona diversa dal cliente omonimo.</label>
                    @endif
                </section>
            @endif

            @if ($this->existing)
                <section>
                    <p>Il cliente ha già una richiesta.</p>
                    @can('view', $this->existing)<a class="crm-link" href="{{ route('gestionale.requests.show', $this->existing) }}" wire:navigate>Apri {{ $this->existing->title }}</a>@endcan
                </section>
            @endif

            <div class="crm-quick-primary">
                <label class="crm-field"><span>Acquisto o locazione</span><select required wire:model.live="operation"><option value="">Scegli</option><option>Acquisto</option><option>Locazione</option></select></label>
                <label class="crm-field"><span>Tipologia</span><select required wire:model="typology"><option value="">Scegli</option>@foreach ($this->typologies as $t)<option>{{ $t }}</option>@endforeach</select></label>
                <label class="crm-field"><span>Zona o Comune</span><input required maxlength="200" list="quick-request-zones" wire:model.live.debounce.400ms="zone"><datalist id="quick-request-zones">@foreach ($zonesConfigured as $z)<option>{{ $z }}</option>@endforeach</datalist></label>
            </div>
            @if ($zoneText !== '' && ! $zoneConfigured)<p class="crm-muted">La zona sarà annotata. Potrai precisarla nel profilo; non applica ancora un filtro geografico al confronto.</p>@endif
            <fieldset class="crm-fieldset"><legend>{{ $operation === 'Locazione' ? 'Canone · €/mese' : 'Budget · €' }}</legend>
                <div class="crm-form-grid">
                    <label class="crm-field"><span>Da</span><input type="number" min="0" step="0.01" wire:model="budgetMin"></label>
                    <label class="crm-field"><span>A</span><input type="number" min="0" step="0.01" wire:model="budgetMax"></label>
                </div>
                <small>Indica l’intervallo oppure un solo limite. Il valore Da non può superare A.</small>
            </fieldset>
            <label class="crm-field"><span>Note libere</span><textarea rows="4" maxlength="{{ $notesMax }}" wire:model="notes" placeholder="Esigenze, preferenze e altre informazioni sulla richiesta"></textarea></label>
            <details class="crm-quick-details" wire:ignore.self><summary>Aggiungi dettagli</summary>
                @foreach ($this->detailQuestions as $question)
                    <fieldset class="crm-fieldset" wire:key="d-{{ $question['id'] }}"><legend>{{ AnswerPresenter::questionLabel($question) }}</legend>
                        <x-gestionale.answer-input :question="$question" :options="$this->questionnaire->options($question, $this->purposeCriteria)" :value="$details[$question['id']] ?? null" model="details.{{ $question['id'] }}" />
                        @if (Questionnaire::hasAnswer(AnswerPresenter::fromField($question, $details[$question['id']] ?? null)))<button type="button" class="crm-link" wire:click="clearDetail('{{ $question['id'] }}')">Lascia da definire</button>@endif
                    </fieldset>
                @endforeach
            </details>
            @foreach (['command', 'client', 'contact_id', 'quick', 'value', 'duplicate'] as $errorKey)
                @error($errorKey)<p class="crm-error" role="alert">{{ $message }}</p>@enderror
            @endforeach
            <button class="crm-btn" type="submit" @disabled((bool) $this->existing) wire:loading.attr="disabled" wire:target="save">Salva richiesta</button>
        </form>
    </x-gestionale.crm-dialog>
    @endif
</div>
