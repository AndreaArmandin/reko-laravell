<?php

use App\Gestionale\Actions\Requests\SavePropertyRequest;
use App\Gestionale\Actions\SetRecordLifecycle;
use App\Gestionale\Livewire\HandlesCommands;
use App\Gestionale\Questionnaire\ProfileFlow;
use App\Gestionale\Questionnaire\Questionnaire;
use App\Models\Contact;
use App\Models\PropertyRequest;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/*
 * Scheda cliente: recapiti, creatore del lead, dettagli, "Che cosa cerca" (la richiesta del cliente),
 * archiviazione e rimozione reversibili. Le attività arrivano con la fase Attività.
 */
new #[Layout('layouts::gestionale')] class extends Component {
    use HandlesCommands;

    public Contact $contact;

    public bool $confirmRemove = false;

    public function mount(Contact $contact): void
    {
        abort_if($contact->clientProfile === null, 404);
        $this->authorize('view', $contact);
        $this->contact = $contact;
    }

    #[Computed]
    public function profile()
    {
        return $this->contact->clientProfile()->with(['agent', 'createdBy', 'lifecycleBy'])->first();
    }

    #[Computed]
    public function requests()
    {
        return PropertyRequest::query()->visibleTo($this->actor())->where('contact_id', $this->contact->id)
            ->orderByDesc('updated_at')->orderByDesc('id')->get();
    }

    /** A request of the same identity (also via a duplicate card): the quick request is not offered. */
    #[Computed]
    public function existing(): ?PropertyRequest
    {
        return SavePropertyRequest::existingFor($this->contact)->first();
    }

    public function lifecycle(string $mode): void
    {
        $done = $this->command(fn () => app(SetRecordLifecycle::class)->handle($this->actor(), $this->profile, $mode, $this->confirmRemove),
            ['archive' => 'Cliente archiviato.', 'remove' => 'Cliente rimosso dalle liste.', 'restore' => 'Cliente ripristinato.'][$mode] ?? null);
        if ($done) {
            $this->confirmRemove = false;
            unset($this->profile);
            \Flux\Flux::modal('remove-client')->close();
        }
    }
}; ?>

@php($p = $this->profile)
@php($questionnaire = App\Gestionale\Questionnaire\Questionnaire::forAgency($contact->agency_id))
<div class="flex flex-col gap-6">
    <div>
        <flux:link :href="route('gestionale.clients.index')" wire:navigate>← Clienti</flux:link>
        <div class="mt-2 flex flex-wrap items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <flux:avatar :name="$contact->display_name" />
                <div>
                    <flux:heading size="xl" level="1">{{ $contact->display_name }}</flux:heading>
                    <div class="mt-1 flex gap-2">
                        <flux:badge size="sm">{{ $p->status }}</flux:badge>
                        @if ($p->lifecycle_state)
                            <flux:badge size="sm" color="zinc">{{ $p->lifecycle_state === 'removed' ? 'Rimosso dalle liste' : 'Archiviato' }}</flux:badge>
                        @endif
                    </div>
                </div>
            </div>
            <div class="flex gap-2">
                @can('update', $contact)
                    <flux:button icon="pencil" :href="route('gestionale.clients.edit', $contact)" wire:navigate>Modifica</flux:button>
                @endcan
                @if ($this->existing)
                    @can('view', $this->existing)
                        <flux:button variant="primary" :href="route('gestionale.requests.show', $this->existing)" wire:navigate>Apri la richiesta</flux:button>
                    @endcan
                @elseif (auth()->user()->can('update', $contact))
                    <flux:button variant="primary" icon="plus" :href="route('gestionale.requests.create', ['cliente' => $contact->id])" wire:navigate>Nuova richiesta</flux:button>
                @endif
            </div>
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <flux:card class="flex flex-col gap-3">
            <flux:heading>Recapiti</flux:heading>
            @if ($contact->primaryPhone)
                <div><flux:text size="sm">Telefono</flux:text><flux:link :href="'tel:'.preg_replace('/[^\d+]/', '', $contact->primaryPhone->value)">{{ $contact->primaryPhone->value }}</flux:link></div>
            @endif
            @if ($contact->primaryEmail)
                <div><flux:text size="sm">Email</flux:text><flux:link :href="'mailto:'.rawurlencode($contact->primaryEmail->value)">{{ $contact->primaryEmail->value }}</flux:link></div>
            @endif
            <div><flux:text size="sm">Creatore del lead</flux:text><flux:text>{{ $p->createdBy?->name ?? 'Non registrato nello storico' }}</flux:text></div>
            <div><flux:text size="sm">Operatore assegnato</flux:text><flux:text>{{ $p->agent?->name }}</flux:text></div>
        </flux:card>

        <flux:card class="flex flex-col gap-3 lg:col-span-2">
            <flux:heading>Dettagli</flux:heading>
            <div class="grid gap-3 md:grid-cols-2">
                <div><flux:text size="sm">Canale preferito</flux:text><flux:text>{{ $p->preferred_channel }}</flux:text></div>
                <div><flux:text size="sm">Fascia di contatto</flux:text><flux:text>{{ $p->contact_time ?: '—' }}</flux:text></div>
                <div><flux:text size="sm">Provenienza</flux:text><flux:text>{{ $p->source ?: '—' }}</flux:text></div>
                <div><flux:text size="sm">Nota di lavoro</flux:text><flux:text>{{ $p->next_action ?: '—' }}</flux:text></div>
                <div class="md:col-span-2"><flux:text size="sm">Note interne</flux:text><flux:text class="whitespace-pre-line">{{ $p->notes ?: '—' }}</flux:text></div>
                <div class="md:col-span-2"><flux:text size="sm">Consensi</flux:text>
                    <flux:text>Gestione della pratica: {{ $p->consent_practice ? 'sì' : 'no' }} · Comunicazioni promozionali: {{ $p->consent_marketing ? 'sì' : 'no' }}</flux:text>
                </div>
            </div>
        </flux:card>
    </div>

    <flux:card class="flex flex-col gap-3">
        <flux:heading>Che cosa cerca</flux:heading>
        @forelse ($this->requests as $request)
            @php($progress = App\Gestionale\Questionnaire\ProfileFlow::baseCompleteness($questionnaire, $request->criteria))
            <div class="flex flex-wrap items-center gap-3" wire:key="r-{{ $request->id }}">
                <flux:link :href="route('gestionale.requests.show', $request)" wire:navigate class="flex-1">{{ $request->title_auto ? 'Richiesta immobiliare' : $request->title }}</flux:link>
                <flux:badge size="sm">{{ $request->status }}</flux:badge>
                <flux:text size="sm">{{ $progress['answered'] }}/{{ $progress['total'] }}</flux:text>
            </div>
        @empty
            <flux:text>Parti da una nuova richiesta.</flux:text>
        @endforelse
    </flux:card>

    <flux:card class="flex flex-col gap-3">
        <flux:heading>Archiviazione e rimozione cliente</flux:heading>
        <flux:text size="sm">Operazioni reversibili: dati, collegamenti e storico non vengono cancellati.</flux:text>
        @error('command') <flux:text class="text-red-600">{{ $message }}</flux:text> @enderror
        <div class="flex flex-wrap gap-2">
            @if ($p->lifecycle_state)
                <flux:button wire:click="lifecycle('restore')">Ripristina</flux:button>
            @else
                @can('archive', $contact)
                    <flux:button wire:click="lifecycle('archive')">Archivia</flux:button>
                @endcan
            @endif
            @if ($this->actor()->isAdmin() && $p->lifecycle_state !== 'removed')
                <flux:modal.trigger name="remove-client"><flux:button variant="danger">Rimuovi dalle liste</flux:button></flux:modal.trigger>
            @endif
            @if ($this->actor()->isAdmin())
                <flux:modal.trigger name="anonymize-info"><flux:button variant="ghost">Anonimizzazione · informazioni</flux:button></flux:modal.trigger>
            @endif
        </div>
    </flux:card>

    <flux:modal name="remove-client" class="max-w-md">
        <div class="flex flex-col gap-4">
            <flux:heading size="lg">Rimuovi dalle liste</flux:heading>
            <flux:text>La scheda esce dalle liste ma resta conservata con tutti i collegamenti. Puoi ripristinarla in qualsiasi momento.</flux:text>
            <flux:checkbox wire:model="confirmRemove" label="Confermo la rimozione reversibile dalla lista" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Annulla</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="lifecycle('remove')">Rimuovi</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="anonymize-info" class="max-w-md">
        <div class="flex flex-col gap-3">
            <flux:heading size="lg">Anonimizzazione</flux:heading>
            <flux:text>L’anonimizzazione dei dati personali non è attiva in questa versione. Nessun dato viene modificato da questa finestra.</flux:text>
            <div class="flex justify-end"><flux:modal.close><flux:button>Chiudi</flux:button></flux:modal.close></div>
        </div>
    </flux:modal>
</div>
