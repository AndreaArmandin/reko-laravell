<?php

use App\Gestionale\Actions\SetRecordLifecycle;
use App\Gestionale\AgencySettings;
use App\Gestionale\Livewire\HandlesCommands;
use App\Gestionale\Properties\TestRecords;
use App\Models\ClientProfile;
use App\Models\Contact;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/*
 * Clienti (clients.tsx Clients): ricerca su nome, telefono ed email; chip Tutti / Da richiamare e filtro per stato
 * (i contatori non cambiano con la ricerca); filtro "richiamare" che arriva dalla panoramica; schede archiviate
 * e rimosse in una sezione a parte. Visibilità: Responsabile tutti, Segreteria solo i propri, Operatore 1 nessuno.
 */
new #[Layout('layouts::gestionale'), Title('Clienti')] class extends Component {
    use HandlesCommands, WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    /** 'richiamare' arriva dal link della panoramica (destination.filter). */
    #[Url(as: 'filtro')]
    public string $filter = '';

    /** 'Tutti', 'Da richiamare' oppure uno dei 9 stati (il filtro scelto in pagina). */
    #[Url(as: 'stato')]
    public string $status = 'Tutti';

    /** standard | test | all — test markers are the explicit names from demo-markers.ts. */
    #[Url(as: 'schede')]
    public string $tests = 'standard';

    public function mount(): void
    {
        $this->authorize('viewAny', Contact::class);
        if ($this->filter !== 'richiamare') {
            $this->filter = '';
        }
        if (! in_array($this->status, ['Tutti', 'Da richiamare', ...ClientProfile::STATUSES], true)) {
            $this->status = 'Tutti';
        }
        if (! in_array($this->tests, TestRecords::TEST_MODES, true)) {
            $this->tests = 'standard';
        }
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    private function visible(): Builder
    {
        return Contact::query()->visibleTo($this->actor())->whereHas('clientProfile');
    }

    private function withTestVisibility(Builder $query): Builder
    {
        return match ($this->tests) {
            'all' => $query,
            'test' => $query->whereIn('contacts.display_name', TestRecords::TEST_CLIENT_NAMES),
            default => $query->whereNotIn('contacts.display_name', TestRecords::TEST_CLIENT_NAMES),
        };
    }

    /** `${name} ${phone} ${email}` contiene il testo cercato, senza distinguere maiuscole (clients.tsx). */
    private function matching(Builder $query): Builder
    {
        if ($this->search === '') {
            return $query;
        }
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($this->search, 'UTF-8')).'%';

        return $query->whereRaw("lower(contacts.display_name || ' ' || coalesce((select pc.value from contact_channels pc where pc.contact_id = contacts.id and pc.kind = 'phone' and pc.is_primary limit 1), '') || ' ' || coalesce((select ec.value from contact_channels ec where ec.contact_id = contacts.id and ec.kind = 'email' and ec.is_primary limit 1), '')) like ?", [$like]);
    }

    #[Computed]
    public function clients()
    {
        return $this->withTestVisibility($this->matching($this->visible()))
            ->whereHas('clientProfile', function (Builder $p) {
                $p->activeRecords();
                if ($this->status === 'Da richiamare') {
                    $p->needsCallback();
                } elseif ($this->status !== 'Tutti') {
                    $p->where('status', $this->status);
                }
                if ($this->filter === 'richiamare') {
                    $p->needsCallback();
                }
            })
            ->with(['clientProfile', 'primaryPhone', 'primaryEmail'])->withCount('propertyRequests')
            ->orderBy('display_name')->orderBy('id')
            ->paginate(25);
    }

    /** I contatori dei chip contano tutte le schede attive visibili: non cambiano con la ricerca. */
    #[Computed]
    public function counts(): array
    {
        return [
            'Tutti' => $this->withTestVisibility($this->visible())->whereHas('clientProfile', fn ($p) => $p->activeRecords())->count(),
            'Da richiamare' => $this->withTestVisibility($this->visible())->whereHas('clientProfile', fn ($p) => $p->activeRecords()->needsCallback())->count(),
        ];
    }

    #[Computed]
    public function testCount(): int
    {
        return $this->visible()->whereIn('contacts.display_name', TestRecords::TEST_CLIENT_NAMES)
            ->whereHas('clientProfile', fn (Builder $p) => $p->activeRecords())->count();
    }

    #[Computed]
    public function inactive()
    {
        return $this->visible()->whereHas('clientProfile', fn ($p) => $p->whereNotNull('lifecycle_state'))
            ->with('clientProfile')->orderBy('display_name')->limit(200)->get();
    }

    #[Computed]
    public function contactDays(): int
    {
        return AgencySettings::contactDays();
    }

    private function lifecycle(int $contactId, string $mode, string $message): void
    {
        $contact = Contact::query()->visibleTo($this->actor())->findOrFail($contactId);
        $this->command(fn () => app(SetRecordLifecycle::class)->handle($this->actor(), $contact->clientProfile, $mode, $mode === 'remove'), $message);
        unset($this->clients, $this->counts, $this->inactive);
    }

    public function archive(int $id): void
    {
        $this->lifecycle($id, 'archive', 'Scheda archiviata. Puoi annullare con “Ripristina”.');
    }

    public function restore(int $id): void
    {
        $this->lifecycle($id, 'restore', 'Scheda ripristinata');
    }

    public function remove(int $id): void
    {
        $this->lifecycle($id, 'remove', 'Scheda rimossa dalla lista. Puoi ripristinarla dagli archiviati.');
    }

    public function removeFilter(): void
    {
        $this->filter = '';
        $this->resetPage();
    }
}; ?>

<div>
    <div class="crm-page-head">
        <div>
            <p class="proto-eyebrow">REKO Gestionale</p>
            <h1>Clienti</h1>
            <p class="crm-muted">Una relazione da seguire, anche quando le esigenze cambiano.</p>
        </div>
        @can('create', App\Models\Contact::class)
            <div class="crm-actions"><button type="button" class="crm-btn crm-mobile-primary" wire:click="$dispatch('open-client-form')"><x-gestionale.lucide name="plus" :size="16" />Nuovo cliente</button></div>
        @endcan
    </div>

    <div class="crm-toolbar">
        <label class="crm-field"><span>Schede da mostrare</span>
            <select wire:model.live="tests">
                <option value="standard">Lista principale · test esclusi</option>
                <option value="test">Solo test ({{ $this->testCount }})</option>
                <option value="all">Tutte, compresi i test</option>
            </select>
        </label>
        <div class="crm-client-search">
            <label class="crm-field"><span>Cerca cliente</span><input type="search" placeholder="Nome, telefono o email" wire:model.live.debounce.300ms="search"></label>
        </div>
        <div class="crm-state-filter">
            <div class="crm-filter-chips" role="group" aria-label="Stato dei clienti">
                @foreach (['Tutti', 'Da richiamare'] as $chip)
                    <button type="button" class="crm-chip" aria-pressed="{{ $status === $chip ? 'true' : 'false' }}" wire:click="$set('status', '{{ $chip }}')">{{ $chip }} ({{ $this->counts[$chip] }})</button>
                @endforeach
            </div>
            <label class="crm-field"><span>Filtra per stato</span><select wire:model.live="status">
                @foreach (['Tutti', 'Da richiamare', ...App\Models\ClientProfile::STATUSES] as $value)
                    <option>{{ $value }}</option>
                @endforeach
            </select></label>
        </div>
        <span>{{ $this->clients->total() }} {{ $this->clients->total() === 1 ? 'cliente' : 'clienti' }}{{ $filter === 'richiamare' ? ' da ricontattare' : '' }}</span>
        @if ($filter !== '')
            <button type="button" class="crm-link" wire:click="removeFilter">Rimuovi filtro</button>
        @endif
    </div>

    @if ($status === 'Da richiamare' || $filter === 'richiamare')
        <p class="crm-muted" role="status">Da richiamare: telefonate scadute, clienti da contattare o senza attività conclusa da più di {{ $this->contactDays }} giorni. Un richiamo futuro già fissato esclude la scheda, anche se lo stato è «Nuovo».</p>
    @endif

    @if ($this->inactive->isNotEmpty())
        <details class="crm-operational-filters">
            <summary>Archiviati e rimossi · {{ $this->inactive->count() }}</summary>
            @foreach ($this->inactive as $archived)
                <div class="crm-record-row" wire:key="archived-{{ $archived->id }}">
                    <a class="crm-link" href="{{ route('gestionale.clients.show', $archived) }}" wire:navigate>{{ $archived->display_name }}</a>
                    <x-gestionale.crm-lifecycle kind="client" :id="$archived->id" :state="$archived->clientProfile->lifecycle_state" :at="$archived->clientProfile->lifecycle_at" :admin="$this->actor()->isAdmin()" />
                </div>
            @endforeach
        </details>
    @endif

    <section class="crm-panel crm-client-list">
        @forelse ($this->clients as $client)
            @php($profile = $client->clientProfile)
            <article class="crm-compact-client" wire:key="client-{{ $client->id }}">
                <a class="crm-record-row crm-client-row" href="{{ route('gestionale.clients.show', $client) }}" wire:navigate>
                    <x-gestionale.crm-avatar :name="$client->display_name" :id="$client->id" />
                <span class="crm-grow"><strong>{{ $client->display_name }}</strong>@if (TestRecords::isTestClient($client))<x-gestionale.crm-pill>TEST · dati fittizi</x-gestionale.crm-pill>@endif<small>{{ $profile->preferred_channel ? 'Canale preferito: '.$profile->preferred_channel : '' }}</small></span>
                    <span class="crm-row-tail"><x-gestionale.crm-pill>{{ $profile->status }}</x-gestionale.crm-pill><small>{{ $client->property_requests_count }} {{ $client->property_requests_count === 1 ? 'richiesta' : 'richieste' }}</small></span>
                    <x-gestionale.lucide name="arrow-up-right" :size="17" />
                </a>
                <x-gestionale.crm-contact-links :name="$client->display_name" :phone="$client->primaryPhone?->value" :email="$client->primaryEmail?->value" />
                <x-gestionale.record-activities :context="['contact_id' => $client->id]" :compact="true" :hide-empty-summary="true" />
            </article>
        @empty
            <div class="crm-empty">Nessun cliente con questi criteri. Modifica la ricerca o crea una scheda.</div>
        @endforelse
    </section>

    {{ $this->clients->links() }}
    <livewire:gestionale.client-form-modal />
</div>
