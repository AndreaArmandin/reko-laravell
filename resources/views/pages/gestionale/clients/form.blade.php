<?php

use App\Gestionale\Actions\Clients\SaveClient;
use App\Gestionale\Clients\ClientMatcher;
use App\Gestionale\Commands;
use App\Gestionale\Livewire\HandlesCommands;
use App\Models\AgencyMembership;
use App\Models\ClientProfile;
use App\Models\Contact;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/*
 * Form cliente (ClientForm, "Nuovo cliente" / "Modifica cliente"): nome e almeno un recapito;
 * dettagli, referente (solo Responsabile, solo in modifica), nota di lavoro, note e consensi.
 * Possibili duplicati segnalati per campo; per salvare una scheda separata serve la conferma.
 * Tutte le regole sono nell'Action SaveClient (client.save).
 */
new #[Layout('layouts::gestionale')] class extends Component {
    use HandlesCommands;

    public ?Contact $contact = null;

    public ?string $revision = null;

    public string $idempotencyKey = '';

    public string $name = '';

    public string $phone = '';

    public string $email = '';

    public string $status = 'Nuovo';

    public string $preferred_channel = 'Telefono';

    public string $contact_time = '';

    public string $source = '';

    public ?int $agent_user_id = null;

    public string $next_action = '';

    public string $notes = '';

    public bool $consent_practice = false;

    public bool $consent_marketing = false;

    public bool $confirm_duplicate = false;

    public bool $details = false;

    public function mount(?Contact $contact = null): void
    {
        if ($contact?->exists) {
            $this->authorize('update', $contact);
            $contact->load(['clientProfile', 'primaryPhone', 'primaryEmail']);
            abort_if($contact->clientProfile === null, 404);
            $p = $contact->clientProfile;
            $this->contact = $contact;
            $this->revision = Commands::revision($p);
            $this->fill([
                'name' => $contact->display_name, 'phone' => (string) $contact->primaryPhone?->value, 'email' => (string) $contact->primaryEmail?->value,
                'status' => $p->status, 'preferred_channel' => $p->preferred_channel, 'contact_time' => (string) $p->contact_time,
                'source' => (string) $p->source, 'agent_user_id' => $p->agent_user_id, 'next_action' => (string) $p->next_action,
                'notes' => (string) $p->notes, 'consent_practice' => $p->consent_practice, 'consent_marketing' => $p->consent_marketing,
            ]);
            $this->details = true;
        } else {
            $this->authorize('create', Contact::class);
            $this->contact = null;
        }
        $this->idempotencyKey = (string) str()->uuid();
    }

    /** Active members (plus the current referent, marked inactive) for the admin's referent picker. */
    #[Computed]
    public function agents()
    {
        return AgencyMembership::query()->where('agency_id', $this->actor()->agency_id)
            ->where(fn ($q) => $q->whereNull('deactivated_at')->when($this->agent_user_id, fn ($q) => $q->orWhere('user_id', $this->agent_user_id)))
            ->with('user')->get()->sortBy(fn ($m) => $m->user->name);
    }

    /** Field-level duplicate hints (same primary phone/email in the agency); names only of visible clients. */
    #[Computed]
    public function duplicates(): array
    {
        $hints = [];
        foreach (['phone' => $this->phone, 'email' => $this->email] as $field => $value) {
            $old = $field === 'phone' ? $this->contact?->primaryPhone?->value : $this->contact?->primaryEmail?->value;
            if (trim($value) === '' || trim($value) === trim((string) $old)) {
                continue;
            }
            $match = ClientMatcher::matches(null, $field === 'phone' ? $value : null, $field === 'email' ? $value : null, $this->contact?->id)
                ->first(fn ($m) => $m->{$field});
            if ($match) {
                $hints[$field] = auth()->user()->can('view', $match->contact) ? $match->contact : false;
            }
        }

        return $hints;
    }

    public function save()
    {
        $actor = $this->actor();
        $input = [
            'name' => $this->name, 'phone' => $this->phone, 'email' => $this->email,
            'status' => $this->status, 'preferred_channel' => $this->preferred_channel,
            'contact_time' => $this->contact_time, 'source' => $this->source,
            'notes' => $this->notes, 'consent_practice' => $this->consent_practice, 'consent_marketing' => $this->consent_marketing,
            'confirm_duplicate' => $this->confirm_duplicate,
        ];
        if ($this->contact) {
            $input += ['next_action' => $this->next_action, 'expected_updated_at' => $this->revision];
            if ($actor->isAdmin()) {
                $input['agent_user_id'] = $this->agent_user_id;
            }
        } else {
            $input['idempotency_key'] = $this->idempotencyKey;
        }

        $saved = $this->command(fn () => app(SaveClient::class)->handle($actor, $input, $this->contact), 'Cliente salvato.');
        if ($saved) {
            return $this->redirectRoute('gestionale.clients.show', $saved, navigate: true);
        }
    }
}; ?>

<div class="crm-form-page crm-form-page--client">
    <a class="crm-back" href="{{ $contact ? route('gestionale.clients.show', $contact) : route('gestionale.clients.index') }}" wire:navigate>← {{ $contact ? $contact->display_name : 'Clienti' }}</a>
    <div class="crm-page-head"><div><p class="proto-eyebrow">REKO Gestionale · Clienti</p><h1>{{ $contact ? 'Modifica cliente' : 'Nuovo cliente' }}</h1><p class="crm-muted">Bastano il nome e almeno un recapito. Gli altri dettagli si possono aggiungere anche dopo.</p></div></div>

    <form wire:submit="save" class="crm-form">
        @error('command') <p class="crm-error" role="alert">{{ $message }}</p> @enderror
        <section class="crm-panel">
            <label class="crm-field"><span>Nome e cognome</span><input wire:model="name" maxlength="120" required autocomplete="off"></label>
            @error('name') <small class="crm-field-error">{{ $message }}</small> @enderror
            <div class="crm-form-grid">
                <label class="crm-field"><span>Telefono</span><input wire:model.live.debounce.400ms="phone" type="tel" maxlength="60" autocomplete="off" placeholder="+39 000 000 0000">
                    @if (isset($this->duplicates['phone']))<small class="crm-contact-warning">Già presente in @if ($this->duplicates['phone'])<a class="crm-link" href="{{ route('gestionale.clients.show', $this->duplicates['phone']) }}" target="_blank">{{ $this->duplicates['phone']->display_name }}</a>@else un’altra scheda dell’agenzia @endif. Verifica prima di salvare.</small>@endif
                    @error('phone')<small class="crm-field-error">{{ $message }}</small>@enderror
                </label>
                <label class="crm-field"><span>Email</span><input wire:model.live.debounce.400ms="email" type="email" maxlength="160" autocomplete="off" placeholder="cliente@example.invalid">
                    @if (isset($this->duplicates['email']))<small class="crm-contact-warning">Già presente in @if ($this->duplicates['email'])<a class="crm-link" href="{{ route('gestionale.clients.show', $this->duplicates['email']) }}" target="_blank">{{ $this->duplicates['email']->display_name }}</a>@else un’altra scheda dell’agenzia @endif. Verifica prima di salvare.</small>@endif
                    @error('email')<small class="crm-field-error">{{ $message }}</small>@enderror
                </label>
            </div>
        </section>

        @if (! empty($this->duplicates) || $errors->has('duplicate'))
            <section class="crm-panel"><h2>Ho verificato i possibili duplicati</h2>
                @error('duplicate')<p class="crm-error" role="alert">{{ $message }}</p>@enderror
                <label class="crm-check"><input type="checkbox" wire:model="confirm_duplicate">È una persona distinta: salva una scheda separata</label>
            </section>
        @endif

        <section class="crm-panel">
            <button class="crm-link" type="button" wire:click="$toggle('details')">{{ $details ? '⌄ Nascondi dettagli' : '› Aggiungi dettagli' }}</button>
            @if ($details)
                <div class="crm-form-grid" style="margin-top:20px">
                    <label class="crm-field"><span>Stato del cliente</span><select wire:model="status">@foreach (App\Models\ClientProfile::STATUSES as $s)<option value="{{ $s }}">{{ $s }}</option>@endforeach</select><small>«Attivo» indica una relazione in corso che va seguita.</small>@error('status')<small class="crm-field-error">{{ $message }}</small>@enderror</label>
                    <label class="crm-field"><span>Canale preferito</span><select wire:model="preferred_channel">@foreach (App\Models\ClientProfile::CHANNELS as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach</select></label>
                    <label class="crm-field"><span>Fascia di contatto</span><input wire:model="contact_time" placeholder="Per esempio: dopo le 18"></label>
                    <label class="crm-field"><span>Provenienza</span><input wire:model="source" placeholder="Per esempio: passaparola, portale, vetrina"></label>
                    @if ($contact)
                        <label class="crm-field"><span>Operatore assegnato</span><select wire:model="agent_user_id" @disabled(! $this->actor()->isAdmin())>@foreach ($this->agents as $m)<option value="{{ $m->user_id }}">{{ $m->user->name }}{{ $m->isActive() ? '' : ' · non attivo' }}</option>@endforeach</select>@error('agent_user_id')<small class="crm-field-error">{{ $message }}</small>@enderror<small>Chi segue il cliente; può essere diverso da chi ha creato il lead.</small></label>
                        <label class="crm-field"><span>Nota di lavoro, senza scadenza in agenda</span><input wire:model="next_action"></label>
                    @endif
                </div>
                <label class="crm-field"><span>Note interne</span><textarea wire:model="notes" rows="3"></textarea></label>
                <fieldset class="crm-fieldset"><legend>Consensi dimostrativi</legend>
                    <label class="crm-check"><input type="checkbox" wire:model="consent_practice">Gestione della pratica</label>
                    <label class="crm-check"><input type="checkbox" wire:model="consent_marketing">Comunicazioni promozionali (separate)</label>
                    <small>Annotazioni di prova, non sostituiscono l’informativa e i consensi reali.</small>
                </fieldset>
            @endif
        </section>

        <div class="crm-form-footer"><button class="crm-btn" type="submit" wire:loading.attr="disabled" wire:target="save"><span wire:loading.remove wire:target="save">Salva cliente</span><span wire:loading wire:target="save">Salvataggio…</span></button><a class="crm-btn secondary" href="{{ $contact ? route('gestionale.clients.show', $contact) : route('gestionale.clients.index') }}" wire:navigate>Annulla</a></div>
    </form>
</div>
