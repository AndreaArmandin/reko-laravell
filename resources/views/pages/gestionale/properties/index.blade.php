<?php

use App\Gestionale\CurrentAgency;
use App\Models\Property;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::gestionale'), Title('Immobili a portafoglio')] class extends Component {
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'stato')]
    public string $status = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Property::class);
        if ($this->status !== '' && ! in_array($this->status, Property::STATUSES, true)) {
            $this->status = '';
        }
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    private function base(): Builder
    {
        $term = trim($this->search);
        return Property::query()->visibleTo(app(CurrentAgency::class)->membership())
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('title', 'ilike', '%'.$term.'%')
                ->orWhere('address', 'ilike', '%'.$term.'%')->orWhere('city', 'ilike', '%'.$term.'%')
                ->orWhere('zone', 'ilike', '%'.$term.'%')))
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status));
    }

    #[Computed]
    public function properties()
    {
        return $this->base()->whereNull('lifecycle_state')->with('municipality')->withCount('matches')
            ->orderByDesc('updated_at')->orderByDesc('id')->paginate(24);
    }

    #[Computed]
    public function archived()
    {
        return $this->base()->whereNotNull('lifecycle_state')->with('municipality')->orderByDesc('updated_at')->limit(100)->get();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'status');
        $this->resetPage();
    }
}; ?>

<div class="flex flex-col gap-6">
    <div class="crm-page-head">
        <div>
            <flux:text size="sm">Immobili</flux:text>
            <flux:heading size="xl" level="1">Portafoglio</flux:heading>
            <flux:text class="mt-1">Incarichi e opportunità commerciali collegabili alle richieste dei clienti.</flux:text>
        </div>
        @can('create', App\Models\Property::class)
            <flux:button variant="primary" icon="plus" :href="route('gestionale.properties.create')" wire:navigate>Nuovo immobile</flux:button>
        @endcan
    </div>

    <div class="flex flex-wrap items-end gap-3">
        <div class="w-full max-w-sm"><flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" label="Cerca immobile" placeholder="Titolo, indirizzo, Comune o zona" /></div>
        <div class="w-56"><flux:select wire:model.live="status" label="Stato commerciale">
            <flux:select.option value="">Tutti gli stati</flux:select.option>
            @foreach (App\Models\Property::STATUSES as $value)<flux:select.option :value="$value">{{ $value }}</flux:select.option>@endforeach
        </flux:select></div>
        @if ($search !== '' || $status !== '')<flux:link as="button" wire:click="clearFilters">Azzera filtri</flux:link>@endif
        <flux:text size="sm">{{ $this->properties->total() }} {{ $this->properties->total() === 1 ? 'immobile' : 'immobili' }}</flux:text>
    </div>

    <div class="grid gap-3 lg:grid-cols-2">
        @forelse ($this->properties as $property)
            <flux:card class="flex flex-col gap-3" wire:key="property-{{ $property->id }}">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <flux:link :href="route('gestionale.properties.show', $property)" wire:navigate class="font-semibold">{{ $property->title }}</flux:link>
                        <flux:text size="sm">{{ $property->address }}@if ($property->civic) {{ $property->civic }}@endif · {{ $property->city ?: $property->municipality?->name }}</flux:text>
                    </div>
                    <flux:badge size="sm">{{ $property->status }}</flux:badge>
                </div>
                <div class="flex flex-wrap gap-x-4 gap-y-1">
                    <flux:text size="sm">{{ $property->features['operation'] ?? 'Operazione non indicata' }}</flux:text>
                    @if (isset($property->features['price']))<flux:text size="sm">{{ number_format((float) $property->features['price'], 0, ',', '.') }} €</flux:text>@endif
                    @if (isset($property->features['area']))<flux:text size="sm">{{ number_format((float) $property->features['area'], 0, ',', '.') }} m²</flux:text>@endif
                    <flux:text size="sm">{{ $property->matches_count }} {{ $property->matches_count === 1 ? 'abbinamento' : 'abbinamenti' }}</flux:text>
                </div>
                <div class="flex gap-2">
                    <flux:button size="sm" :href="route('gestionale.properties.show', $property)" wire:navigate>Apri scheda</flux:button>
                    @can('update', $property)<flux:button size="sm" variant="ghost" :href="route('gestionale.properties.edit', $property)" wire:navigate>Modifica</flux:button>@endcan
                </div>
            </flux:card>
        @empty
            <flux:card class="lg:col-span-2"><flux:text>Nessun immobile con questi filtri. Crea una scheda per iniziare il portafoglio.</flux:text></flux:card>
        @endforelse
    </div>
    {{ $this->properties->links() }}

    @if ($this->archived->isNotEmpty())
        <section class="flex flex-col gap-2">
            <flux:heading size="lg">Archivio · {{ $this->archived->count() }}</flux:heading>
            @foreach ($this->archived as $property)
                <flux:card class="flex items-center gap-3">
                    <flux:link class="flex-1" :href="route('gestionale.properties.show', $property)" wire:navigate>{{ $property->title }} · {{ $property->city ?: $property->municipality?->name }}</flux:link>
                    <flux:badge color="zinc">{{ $property->lifecycle_state === 'removed' ? 'Rimosso' : 'Archiviato' }}</flux:badge>
                </flux:card>
            @endforeach
        </section>
    @endif
</div>
