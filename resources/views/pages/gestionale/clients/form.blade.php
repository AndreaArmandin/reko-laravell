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

<div class="mx-auto flex max-w-3xl flex-col gap-6">
    <div>
        <flux:link :href="$contact ? route('gestionale.clients.show', $contact) : route('gestionale.clients.index')" wire:navigate>← {{ $contact ? $contact->display_name : 'Clienti' }}</flux:link>
        <flux:heading size="xl" level="1" class="mt-2">{{ $contact ? 'Modifica cliente' : 'Nuovo cliente' }}</flux:heading>
        <flux:text class="mt-1">Bastano il nome e almeno un recapito. Gli altri dettagli si possono aggiungere anche dopo.</flux:text>
    </div>

    <form wire:submit="save" class="flex flex-col gap-5">
        @error('command') <flux:callout variant="danger" icon="exclamation-triangle" :heading="$message" /> @enderror

        <flux:input wire:model="name" label="Nome e cognome" maxlength="120" required />
        <div class="grid gap-4 md:grid-cols-2">
            <div>
                <flux:input wire:model.live.debounce.400ms="phone" label="Telefono" type="tel" maxlength="60" />
                @if (isset($this->duplicates['phone']))
                    <flux:text size="sm" class="mt-1 text-amber-700">Già presente in
                        @if ($this->duplicates['phone']) <flux:link :href="route('gestionale.clients.show', $this->duplicates['phone'])" target="_blank">{{ $this->duplicates['phone']->display_name }}</flux:link>@else un’altra scheda dell’agenzia @endif.
                        Verifica prima di salvare.</flux:text>
                @endif
            </div>
            <div>
                <flux:input wire:model.live.debounce.400ms="email" label="Email" type="email" maxlength="160" />
                @if (isset($this->duplicates['email']))
                    <flux:text size="sm" class="mt-1 text-amber-700">Già presente in
                        @if ($this->duplicates['email']) <flux:link :href="route('gestionale.clients.show', $this->duplicates['email'])" target="_blank">{{ $this->duplicates['email']->display_name }}</flux:link>@else un’altra scheda dell’agenzia @endif.
                        Verifica prima di salvare.</flux:text>
                @endif
            </div>
        </div>
        @error('name') <flux:text class="text-red-600">{{ $message }}</flux:text> @enderror
        @error('phone') <flux:text class="text-red-600">{{ $message }}</flux:text> @enderror
        @error('email') <flux:text class="text-red-600">{{ $message }}</flux:text> @enderror

        @if (! empty($this->duplicates) || $errors->has('duplicate'))
            <flux:callout variant="warning" icon="exclamation-triangle">
                <flux:callout.heading>Ho verificato i possibili duplicati</flux:callout.heading>
                @error('duplicate') <flux:callout.text>{{ $message }}</flux:callout.text> @enderror
                <flux:checkbox wire:model="confirm_duplicate" label="È una persona distinta: salva una scheda separata" />
            </flux:callout>
        @endif

        <div>
            <flux:button variant="ghost" size="sm" :icon="$details ? 'chevron-down' : 'chevron-right'" wire:click="$toggle('details')" type="button">Aggiungi dettagli</flux:button>
        </div>

        @if ($details)
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <flux:select wire:model="status" label="Stato del cliente">
                        @foreach (App\Models\ClientProfile::STATUSES as $s)
                            <flux:select.option :value="$s">{{ $s }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:text size="sm" class="mt-1">«Attivo»: il cliente sta cercando e va seguito con proposte.</flux:text>
                    @error('status') <flux:text class="text-red-600">{{ $message }}</flux:text> @enderror
                </div>
                <flux:select wire:model="preferred_channel" label="Canale preferito">
                    @foreach (App\Models\ClientProfile::CHANNELS as $c)
                        <flux:select.option :value="$c">{{ $c }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="contact_time" label="Fascia di contatto" placeholder="Per esempio: dopo le 18" />
                <flux:input wire:model="source" label="Provenienza" placeholder="Per esempio: passaparola, portale, vetrina" />
                @if ($contact)
                    <div>
                        <flux:select wire:model="agent_user_id" label="Operatore assegnato" :disabled="! $this->actor()->isAdmin()">
                            @foreach ($this->agents as $m)
                                <flux:select.option :value="$m->user_id">{{ $m->user->name }}{{ $m->isActive() ? '' : ' · non attivo' }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        @error('agent_user_id') <flux:text class="text-red-600">{{ $message }}</flux:text> @enderror
                    </div>
                    <flux:input wire:model="next_action" label="Nota di lavoro" />
                @endif
            </div>
            <flux:textarea wire:model="notes" label="Note interne" rows="3" />
            <flux:fieldset>
                <flux:legend>Consensi</flux:legend>
                <flux:checkbox wire:model="consent_practice" label="Gestione della pratica" />
                <flux:checkbox wire:model="consent_marketing" label="Comunicazioni promozionali (separate)" />
            </flux:fieldset>
        @endif

        <div class="flex gap-2">
            <flux:button type="submit" variant="primary">Salva cliente</flux:button>
            <flux:button :href="$contact ? route('gestionale.clients.show', $contact) : route('gestionale.clients.index')" variant="ghost" wire:navigate>Annulla</flux:button>
        </div>
    </form>
</div>
