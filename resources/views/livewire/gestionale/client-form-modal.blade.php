<?php

use App\Gestionale\Actions\Clients\SaveClient;
use App\Gestionale\Clients\ClientMatcher;
use App\Gestionale\Commands;
use App\Gestionale\Livewire\HandlesCommands;
use App\Models\AgencyMembership;
use App\Models\ClientProfile;
use App\Models\Contact;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/* clients.tsx ClientForm: la stessa scheda si apre sopra la lista o il dettaglio cliente. */
new class extends Component {
    use HandlesCommands;

    public bool $open = false;
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

    #[On('open-client-form')]
    public function showForm(?int $contactId = null): void
    {
        $this->resetValidation();
        $this->resetErrorBag();
        $this->open = false;
        $this->contact = null;
        $this->revision = null;
        $this->name = '';
        $this->phone = '';
        $this->email = '';
        $this->status = 'Nuovo';
        $this->preferred_channel = 'Telefono';
        $this->contact_time = '';
        $this->source = '';
        $this->agent_user_id = null;
        $this->next_action = '';
        $this->notes = '';
        $this->consent_practice = false;
        $this->consent_marketing = false;
        $this->confirm_duplicate = false;
        $this->idempotencyKey = (string) str()->uuid();

        if ($contactId !== null) {
            $contact = Contact::query()->visibleTo($this->actor())->with(['clientProfile', 'primaryPhone', 'primaryEmail'])->findOrFail($contactId);
            $this->authorize('update', $contact);
            abort_if($contact->clientProfile === null, 404);
            $profile = $contact->clientProfile;
            $this->contact = $contact;
            $this->revision = Commands::revision($profile);
            $this->name = $contact->display_name;
            $this->phone = (string) $contact->primaryPhone?->value;
            $this->email = (string) $contact->primaryEmail?->value;
            $this->status = $profile->status;
            $this->preferred_channel = $profile->preferred_channel;
            $this->contact_time = (string) $profile->contact_time;
            $this->source = (string) $profile->source;
            $this->agent_user_id = $profile->agent_user_id;
            $this->next_action = (string) $profile->next_action;
            $this->notes = (string) $profile->notes;
            $this->consent_practice = $profile->consent_practice;
            $this->consent_marketing = $profile->consent_marketing;
        } else {
            $this->authorize('create', Contact::class);
        }

        $this->open = true;
    }

    #[Computed]
    public function agents()
    {
        return AgencyMembership::query()->where('agency_id', $this->actor()->agency_id)
            ->where(fn ($q) => $q->whereNull('deactivated_at')->when($this->agent_user_id, fn ($q) => $q->orWhere('user_id', $this->agent_user_id)))
            ->with('user')->get()->sortBy(fn ($m) => $m->user->name);
    }

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

    public function openExistingContact(int $contactId): void
    {
        $contact = Contact::query()->visibleTo($this->actor())->whereHas('clientProfile')->findOrFail($contactId);
        $this->open = false;
        $this->redirectRoute('gestionale.clients.show', $contact, navigate: true);
    }

    public function close(): void
    {
        $this->open = false;
        $this->resetValidation();
    }

    public function save(): void
    {
        $actor = $this->actor();
        $input = [
            'name' => $this->name, 'phone' => $this->phone, 'email' => $this->email,
            'status' => $this->status, 'preferred_channel' => $this->preferred_channel,
            'contact_time' => $this->contact_time, 'source' => $this->source,
            'notes' => $this->notes, 'consent_practice' => $this->consent_practice,
            'consent_marketing' => $this->consent_marketing, 'confirm_duplicate' => $this->confirm_duplicate,
        ];
        if ($this->contact !== null) {
            $input += ['next_action' => $this->next_action, 'expected_updated_at' => $this->revision];
            if ($actor->isAdmin()) {
                $input['agent_user_id'] = $this->agent_user_id;
            }
        } else {
            $input['idempotency_key'] = $this->idempotencyKey;
        }

        $saved = $this->command(fn () => app(SaveClient::class)->handle($actor, $input, $this->contact), 'Cliente salvato.');
        if ($saved !== null) {
            $this->redirectRoute('gestionale.clients.show', $saved, navigate: true);
        }
    }
}; ?>

<div>
    @if ($open)
        <x-gestionale.crm-dialog :title="$contact ? 'Modifica cliente' : 'Nuovo cliente'" :wide="true" :close="'$wire.close()'">
            <form class="crm-form" wire:submit="save">
                <p class="crm-muted">Bastano il nome e almeno un recapito. Puoi completare gli altri dettagli in seguito. Per le prove usa dati di fantasia.</p>
                @error('command')<p class="crm-error" role="alert">{{ $message }}</p>@enderror
                <label class="crm-field"><span>Nome e cognome</span><input wire:model="name" maxlength="120" required autocomplete="off">@error('name')<small class="crm-field-error">{{ $message }}</small>@enderror</label>
                <div class="crm-form-grid">
                    <label class="crm-field"><span>Telefono</span><input wire:model.live.debounce.400ms="phone" type="tel" maxlength="60" autocomplete="off" placeholder="+39 000 000 0000">
                        @if (isset($this->duplicates['phone']))<small class="crm-contact-warning">Già presente in @if ($this->duplicates['phone'])<button type="button" class="crm-link" wire:click="openExistingContact({{ $this->duplicates['phone']->id }})">{{ $this->duplicates['phone']->display_name }}</button>@else un’altra scheda dell’agenzia @endif. Verifica prima di salvare.</small>@endif
                        @error('phone')<small class="crm-field-error">{{ $message }}</small>@enderror
                    </label>
                    <label class="crm-field"><span>Email</span><input wire:model.live.debounce.400ms="email" type="email" maxlength="160" autocomplete="off" placeholder="cliente@example.invalid">
                        @if (isset($this->duplicates['email']))<small class="crm-contact-warning">Già presente in @if ($this->duplicates['email'])<button type="button" class="crm-link" wire:click="openExistingContact({{ $this->duplicates['email']->id }})">{{ $this->duplicates['email']->display_name }}</button>@else un’altra scheda dell’agenzia @endif. Verifica prima di salvare.</small>@endif
                        @error('email')<small class="crm-field-error">{{ $message }}</small>@enderror
                    </label>
                </div>

                <details @if ($contact) open @endif>
                    <summary>{{ $contact ? 'Altri dati del cliente' : 'Aggiungi dettagli' }}</summary>
                    <div class="crm-form-grid">
                        <label class="crm-field"><span>Stato del cliente</span><select wire:model="status">@foreach (ClientProfile::STATUSES as $value)<option value="{{ $value }}">{{ $value }}</option>@endforeach</select><small>«Attivo» indica una relazione in corso. Puoi cambiarlo qui quando cambia la situazione.</small>@error('status')<small class="crm-field-error">{{ $message }}</small>@enderror</label>
                        <label class="crm-field"><span>Canale preferito</span><select wire:model="preferred_channel">@foreach (ClientProfile::CHANNELS as $value)<option value="{{ $value }}">{{ $value }}</option>@endforeach</select></label>
                        <label class="crm-field"><span>Fascia di contatto</span><input wire:model="contact_time" placeholder="Es. pomeriggio"></label>
                        <label class="crm-field"><span>Provenienza</span><input wire:model="source" placeholder="Es. segnalazione"></label>
                        @if ($contact)
                            <label class="crm-field"><span>Operatore assegnato</span><select wire:model="agent_user_id" @disabled(! $this->actor()->isAdmin())>@foreach ($this->agents as $member)<option value="{{ $member->user_id }}">{{ $member->user->name }}{{ $member->isActive() ? '' : ' · non attivo' }}</option>@endforeach</select><small>Chi segue il cliente; può essere diverso dal creatore del lead.</small>@error('agent_user_id')<small class="crm-field-error">{{ $message }}</small>@enderror</label>
                            <label class="crm-field"><span>Nota di lavoro (senza scadenza in agenda)</span><input wire:model="next_action"></label>
                        @endif
                    </div>
                    <label class="crm-field"><span>Note interne</span><textarea wire:model="notes" rows="3"></textarea></label>
                    <fieldset class="crm-fieldset"><legend>Consensi dimostrativi</legend>
                        <label class="crm-check"><input type="checkbox" wire:model="consent_practice">Gestione della pratica</label>
                        <label class="crm-check"><input type="checkbox" wire:model="consent_marketing">Comunicazioni promozionali (separate)</label>
                        <small>Annotazioni di prova, non sostituiscono l’informativa e i consensi reali.</small>
                    </fieldset>
                </details>
                @if ($this->duplicates !== [] || $errors->has('duplicate'))
                    <details><summary>Ho verificato i possibili duplicati</summary>
                        @error('duplicate')<p class="crm-error" role="alert">{{ $message }}</p>@enderror
                        <label class="crm-check"><input type="checkbox" wire:model="confirm_duplicate">È una persona distinta: salva una scheda separata</label>
                    </details>
                @endif
                <div class="crm-form-footer"><button class="crm-btn" type="submit" wire:loading.attr="disabled" wire:target="save"><span wire:loading.remove wire:target="save">Salva cliente</span><span wire:loading wire:target="save">Salvataggio…</span></button></div>
            </form>
        </x-gestionale.crm-dialog>
    @endif
</div>
