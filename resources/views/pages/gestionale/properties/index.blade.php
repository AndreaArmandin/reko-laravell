<?php

use App\Gestionale\Livewire\HandlesCommands;
use App\Gestionale\Livewire\ManagesPropertyLifecycle;
use App\Gestionale\Properties\PropertyFilters;
use App\Models\Property;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/*
 * Immobili a portafoglio (properties.tsx Properties): le opportunità commerciali dell'agenzia.
 * Filtri: ricerca (titolo, indirizzo, civico, zona, codice), stato commerciale, schede di prova (lista principale
 * senza test, solo test, tutte), e dalla panoramica "incompleti" e "scadenza" (incarico che scade entro i giorni
 * di promemoria). Archiviati e rimossi in una sezione a parte, con ripristino per riga.
 * Visibilità: Responsabile tutti, Segreteria solo i propri, Operatore nessuno.
 */
new #[Layout('layouts::gestionale'), Title('Immobili a portafoglio')] class extends Component {
    use HandlesCommands, ManagesPropertyLifecycle, WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'stato')]
    public string $status = '';

    /** '' | incompleti | scadenza */
    #[Url(as: 'filtro')]
    public string $filter = '';

    /** standard | test | all */
    #[Url(as: 'schede')]
    public string $tests = 'standard';

    public function mount(): void
    {
        $this->authorize('viewAny', Property::class);
        if ($this->status !== '' && ! in_array($this->status, Property::STATUSES, true)) {
            $this->status = '';
        }
        if (! in_array($this->filter, ['', 'incompleti', 'scadenza'], true)) {
            $this->filter = '';
        }
        if (! in_array($this->tests, PropertyFilters::TEST_MODES, true)) {
            $this->tests = 'standard';
        }
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    private function visible(): Builder
    {
        return Property::query()->visibleTo($this->actor());
    }

    #[Computed]
    public function properties()
    {
        $query = PropertyFilters::testVisibility(PropertyFilters::search($this->visible()->whereNull('lifecycle_state'), $this->search), $this->tests)
            ->when($this->status !== '', fn (Builder $q) => $q->where('status', $this->status));
        if ($this->filter === 'incompleti') {
            PropertyFilters::incomplete($query);
        } elseif ($this->filter === 'scadenza') {
            PropertyFilters::expiring($query);
        }

        return $query->orderBy('id')->paginate(30);
    }

    /** Schede di prova fra quelle attive: il numero di "Solo test". */
    #[Computed]
    public function testCount(): int
    {
        return PropertyFilters::testVisibility($this->visible()->whereNull('lifecycle_state'), 'test')->count();
    }

    #[Computed]
    public function archived()
    {
        return $this->visible()->whereNotNull('lifecycle_state')->orderBy('id')->get(['id', 'title', 'lifecycle_state', 'lifecycle_at']);
    }

    #[Computed]
    public function admin(): bool
    {
        return $this->actor()->isAdmin();
    }

    public function afterLifecycle(int $id): void
    {
        unset($this->properties, $this->archived, $this->testCount);
    }

    public function clearFilter(): void
    {
        $this->filter = '';
        $this->resetPage();
    }
}; ?>

<div>
    <div class="crm-page-head">
        <div>
            <p class="proto-eyebrow">REKO Gestionale</p>
            <h1>Immobili a portafoglio</h1>
            <p class="crm-muted">Le opportunità commerciali dell’agenzia. Non tutte le unità catastali sono immobili da proporre.</p>
        </div>
        @can('create', App\Models\Property::class)
            <div class="crm-actions">
                <a class="crm-btn" href="{{ route('gestionale.properties.create') }}" wire:navigate><x-gestionale.lucide name="plus" :size="16" />Nuovo immobile</a>
            </div>
        @endcan
    </div>

    <div class="crm-toolbar">
        <label class="crm-field"><span>Cerca immobile</span>
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Titolo, indirizzo, civico, zona o codice">
        </label>
        <label class="crm-field"><span>Schede da mostrare</span>
            <select wire:model.live="tests">
                <option value="standard">Lista principale · test esclusi</option>
                <option value="test">Solo test ({{ $this->testCount }})</option>
                <option value="all">Tutte, compresi i test</option>
            </select>
        </label>
        <label class="crm-field"><span>Stato commerciale</span>
            <select wire:model.live="status">
                <option value="">Tutti</option>
                @foreach (App\Models\Property::STATUSES as $value)<option value="{{ $value }}">{{ $value }}</option>@endforeach
            </select>
        </label>
        <span>{{ $this->properties->total() }} {{ $this->properties->total() === 1 ? 'immobile' : 'immobili' }}{{ $filter === 'scadenza' ? ' · consultazione storica, gestione incarichi sospesa' : ($filter === 'incompleti' ? ' · schede / annunci da completare' : '') }}</span>
        @if ($filter !== '')<button type="button" class="crm-link" wire:click="clearFilter">Rimuovi filtro</button>@endif
    </div>

    @if ($this->archived->isNotEmpty())
        <details class="crm-operational-filters">
            <summary>Archiviati e rimossi · {{ $this->archived->count() }}</summary>
            @foreach ($this->archived as $item)
                <div class="crm-record-row" wire:key="archived-{{ $item->id }}">
                    <a class="crm-link" href="{{ route('gestionale.properties.show', $item->id) }}" wire:navigate>{{ $item->title }}</a>
                    <x-gestionale.crm-lifecycle kind="property" :id="$item->id" :state="$item->lifecycle_state" :at="$item->lifecycle_at" :admin="$this->admin" />
                </div>
            @endforeach
        </details>
    @endif

    <div class="crm-property-grid">
        @foreach ($this->properties as $property)
            <x-gestionale.property-card :property="$property" />
        @endforeach
    </div>
    @if ($this->properties->isEmpty())<div class="crm-empty">Nessun immobile accessibile con questi filtri.</div>@endif
    {{ $this->properties->links() }}
</div>
