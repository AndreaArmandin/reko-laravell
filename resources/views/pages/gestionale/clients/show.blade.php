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
 * Scheda cliente: recapiti, richieste, attività e conservazione reversibile come in clients.tsx. Il form di modifica è
 * la finestra <livewire:gestionale.client-form-modal>, aperta anche dalla route clienti/{id}/modifica.
 */
new #[Layout('layouts::gestionale'), Title('Scheda cliente')] class extends Component {
    use HandlesCommands;

    public Contact $contact;

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

    /** record-lifecycle.tsx act(): la conferma della rimozione è la casella nella finestra di <x-gestionale.crm-lifecycle>. */
    public function lifecycle(string $mode, bool $confirmed = false): void
    {
        $done = $this->command(fn () => app(SetRecordLifecycle::class)->handle($this->actor(), $this->profile, $mode, $confirmed), [
            'archive' => 'Scheda archiviata. Puoi annullare con “Ripristina”.',
            'remove' => 'Scheda rimossa dalla lista. Puoi ripristinarla dagli archiviati.',
            'restore' => 'Scheda ripristinata',
        ][$mode] ?? null);
        if ($done) {
            unset($this->profile);
        }
    }

    public function archive(int $id): void
    {
        $this->lifecycle('archive');
    }

    public function restore(int $id): void
    {
        $this->lifecycle('restore');
    }

    public function remove(int $id): void
    {
        $this->lifecycle('remove', true);
    }
}; ?>

@php($p = $this->profile)
@php($links = App\Gestionale\Clients\ClientCard::contactLinks($contact->primaryPhone?->value, $contact->primaryEmail?->value))
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
                <a class="crm-btn" href="{{ route('gestionale.clients.show', ['contact' => $contact->id, 'nuovaRichiesta' => 1]) }}" wire:navigate><x-gestionale.lucide name="plus" :size="16" />Nuova richiesta</a>
            @endcan
        </div>
    </div>

    @if (App\Gestionale\Properties\TestRecords::isTestClient($contact))<p class="crm-contact-warning" role="note">TEST · Scheda di prova, non un cliente reale.</p>@endif
    <x-gestionale.record-activities :context="['contact_id' => $contact->id]" :person-actions="true" :menu-label="'Azioni per '.$contact->display_name" />

    <div class="crm-detail-grid">
        <section class="crm-panel">
            <h2>Recapiti</h2>
            <dl class="crm-facts">
                <div><dt>Numero di telefono</dt><dd>@if ($links['phone'] !== '')@if ($links['tel'])<a href="{{ $links['tel'] }}">{{ $contact->primaryPhone->value }}</a>@else{{ $contact->primaryPhone->value }}@endif@else Non indicato @endif</dd></div>
                <div><dt>Email</dt><dd>@if ($links['email'] !== '')@if ($links['emailHref'])<a href="{{ $links['emailHref'] }}">{{ $contact->primaryEmail->value }}</a>@else{{ $contact->primaryEmail->value }}@endif@else Non indicata @endif</dd></div>
                <div><dt>Creatore del lead</dt><dd>{{ $p->createdBy?->name ?? 'Non registrato nello storico' }}</dd></div>
            </dl>
        </section>

        <section class="crm-panel">
            <h2>Che cosa cerca</h2>
            @forelse ($this->requests as $request)
                @php($progress = ProfileFlow::baseCompleteness($questionnaire, $request->criteria))
                <x-gestionale.crm-request-row :request="$request" :progress="$progress" :show-client="false" :show-activities="false" />
            @empty
                <div class="crm-empty">Parti da una nuova richiesta. Le successive rimarranno indipendenti.</div>
            @endforelse
        </section>
    </div>

    <section class="crm-panel">
        <h2>Archiviazione e rimozione cliente</h2>
        <p>Puoi togliere il cliente dalle liste attive senza perdere richieste e storico.</p>
        @error('command')<p class="crm-error" role="alert">{{ $message }}</p>@enderror
        <x-gestionale.crm-lifecycle kind="client" :id="$contact->id" :state="$p->lifecycle_state" :at="$p->lifecycle_at" :admin="$this->actor()->isAdmin()" />
    </section>

    <livewire:gestionale.client-form-modal :auto-open="request()->routeIs('gestionale.clients.edit') ? $contact->id : null" />
    <livewire:gestionale.request-form-modal :auto-open="request()->routeIs('gestionale.clients.show') && request()->boolean('nuovaRichiesta') ? (string) $contact->id : null" />
</div>
