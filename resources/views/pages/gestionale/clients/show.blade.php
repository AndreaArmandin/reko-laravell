<?php

use App\Gestionale\Actions\SetRecordLifecycle;
use App\Gestionale\Livewire\HandlesCommands;
use App\Gestionale\Questionnaire\ProfileFlow;
use App\Models\Contact;
use App\Models\PropertyRequest;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Scheda cliente: recapiti, richieste, attività e conservazione reversibile come in clients.tsx.
 */
new #[Layout('layouts::gestionale'), Title('Scheda cliente')] class extends Component {
    use HandlesCommands;

    public Contact $contact;

    public bool $confirmRemove = false;

    public bool $removeDialogOpen = false;

    public bool $anonymizeInfoOpen = false;

    public function mount(Contact $contact): void
    {
        abort_if($contact->clientProfile === null, 404);
        $this->authorize('view', $contact);
        $this->contact = $contact;
    }

    #[Computed]
    public function profile()
    {
        return $this->contact->clientProfile()->with(['createdBy', 'lifecycleBy'])->first();
    }

    #[Computed]
    public function requests()
    {
        return PropertyRequest::query()->visibleTo($this->actor())->where('contact_id', $this->contact->id)
            ->orderByDesc('updated_at')->orderByDesc('id')->get();
    }

    public function lifecycle(string $mode): void
    {
        $done = $this->command(fn () => app(SetRecordLifecycle::class)->handle($this->actor(), $this->profile, $mode, $this->confirmRemove),
            ['archive' => 'Cliente archiviato.', 'remove' => 'Cliente rimosso dalle liste.', 'restore' => 'Cliente ripristinato.'][$mode] ?? null);
        if ($done) {
            $this->confirmRemove = false;
            $this->removeDialogOpen = false;
            unset($this->profile);
        }
    }

    public function openRemoveDialog(): void
    {
        abort_unless($this->actor()->isAdmin() && $this->profile->lifecycle_state !== 'removed', 403);
        $this->confirmRemove = false;
        $this->removeDialogOpen = true;
    }

    public function closeRemoveDialog(): void
    {
        $this->removeDialogOpen = false;
        $this->confirmRemove = false;
    }

    public function openAnonymizeInfo(): void
    {
        abort_unless($this->actor()->isAdmin(), 403);
        $this->anonymizeInfoOpen = true;
    }

    public function closeAnonymizeInfo(): void
    {
        $this->anonymizeInfoOpen = false;
    }

}; ?>

@php($p = $this->profile)
@php($questionnaire = App\Gestionale\Questionnaire\Questionnaire::forAgency($contact->agency_id))
<div class="crm-record-page">
    <a class="crm-back" href="{{ route('gestionale.clients.index') }}" wire:navigate><x-gestionale.lucide name="arrow-left" :size="16" />Tutti i clienti</a>
    <div class="crm-page-head">
        <div>
            <p class="proto-eyebrow">REKO Gestionale</p>
            <h1>{{ $contact->display_name }}</h1>
        </div>
        <div class="crm-actions">
            @can('update', $contact)
                <button type="button" class="crm-btn secondary" wire:click="$dispatch('open-client-form', { contactId: {{ $contact->id }} })"><x-gestionale.lucide name="pencil" :size="16" />Modifica</button>
                <a class="crm-btn" href="{{ route('gestionale.requests.create', ['cliente' => $contact->id]) }}" wire:navigate><x-gestionale.lucide name="plus" :size="16" />Nuova richiesta</a>
            @endcan
        </div>
    </div>

    @if (App\Gestionale\Properties\TestRecords::isTestClient($contact))<p class="crm-contact-warning" role="note">TEST · Scheda di prova, non un cliente reale.</p>@endif
    <x-gestionale.record-activities :context="['contact_id' => $contact->id]" />

    <div class="crm-detail-grid">
        <section class="crm-panel">
            <h2>Recapiti</h2>
            <dl class="crm-facts">
                <div><dt>Numero di telefono</dt><dd>@if ($contact->primaryPhone)<a href="tel:{{ preg_replace('/[^\d+]/', '', $contact->primaryPhone->value) }}">{{ $contact->primaryPhone->value }}</a>@else Non indicato @endif</dd></div>
                <div><dt>Email</dt><dd>@if ($contact->primaryEmail)<a href="mailto:{{ rawurlencode($contact->primaryEmail->value) }}">{{ $contact->primaryEmail->value }}</a>@else Non indicata @endif</dd></div>
                <div><dt>Creatore del lead</dt><dd>{{ $p->createdBy?->name ?? 'Non registrato nello storico' }}</dd></div>
            </dl>
        </section>

        <section class="crm-panel">
            <h2>Che cosa cerca</h2>
            @forelse ($this->requests as $request)
                @php($progress = ProfileFlow::baseCompleteness($questionnaire, $request->criteria))
                <x-gestionale.crm-request-row :request="$request" :progress="$progress" :show-client="false" :show-activities="false" />
            @empty
                <p class="crm-empty">Parti da una nuova richiesta. Le successive rimarranno indipendenti.</p>
            @endforelse
        </section>
    </div>

    <section class="crm-panel">
        <h2>Archiviazione e rimozione cliente</h2>
        <p class="crm-muted">Operazioni reversibili: dati, collegamenti e storico vengono conservati.</p>
        @error('command')<p class="crm-error" role="alert">{{ $message }}</p>@enderror
        <div class="crm-actions">
            @if ($p->lifecycle_state)
                <button class="crm-btn secondary" wire:click="lifecycle('restore')">Ripristina</button>
            @else
                @can('archive', $contact)<button class="crm-btn secondary" wire:click="lifecycle('archive')">Archivia</button>@endcan
            @endif
            @if ($this->actor()->isAdmin() && $p->lifecycle_state !== 'removed')<button type="button" class="crm-btn secondary" wire:click="openRemoveDialog">Rimuovi dalle liste</button>@endif
            @if ($this->actor()->isAdmin())<button type="button" class="crm-btn secondary" wire:click="openAnonymizeInfo">Anonimizzazione · informazioni</button>@endif
        </div>
    </section>

    @if ($removeDialogOpen)
        <x-gestionale.crm-dialog title="Rimuovi dalle liste" label="Scheda cliente" :close="'$wire.closeRemoveDialog()'">
            <div class="crm-form"><p class="crm-muted">La scheda esce dalle liste ma resta conservata con tutti i collegamenti. Puoi ripristinarla in qualsiasi momento.</p>
                <label class="crm-check"><input type="checkbox" wire:model="confirmRemove">Confermo la rimozione reversibile dalla lista</label>
                <div class="crm-actions"><button type="button" class="crm-btn secondary" wire:click="closeRemoveDialog">Annulla</button><button type="button" class="crm-btn" wire:click="lifecycle('remove')">Rimuovi</button></div>
            </div>
        </x-gestionale.crm-dialog>
    @endif
    @if ($anonymizeInfoOpen)
        <x-gestionale.crm-dialog title="Anonimizzazione" label="Scheda cliente" :close="'$wire.closeAnonymizeInfo()'">
            <div class="crm-form"><p class="crm-muted">L’anonimizzazione dei dati personali non è attiva in questa versione. Nessun dato viene modificato da questa finestra.</p><div class="crm-actions"><button type="button" class="crm-btn" wire:click="closeAnonymizeInfo">Chiudi</button></div></div>
        </x-gestionale.crm-dialog>
    @endif

    <livewire:gestionale.client-form-modal />
</div>
