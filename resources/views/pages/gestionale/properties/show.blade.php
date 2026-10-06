<?php

use App\Gestionale\CurrentAgency;
use App\Gestionale\Actions\Properties\SetPropertyLifecycle;
use App\Gestionale\Livewire\HandlesCommands;
use App\Models\Activity;
use App\Models\Property;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::gestionale')] class extends Component {
    use HandlesCommands;

    public Property $property;

    public function mount(Property $property): void
    {
        $this->authorize('view', $property);
        $this->property = $property->load(['municipality', 'units.cadastralUnit.parcel', 'contacts.contact', 'matches.propertyRequest.contact', 'activities']);
    }

    public function restore(): void
    {
        $this->authorize('archive', $this->property);
        if ($this->property->lifecycle_state === null) return;
        $this->property = $this->command(fn () => app(SetPropertyLifecycle::class)->handle($this->actor(), $this->property, 'restore'), 'Immobile ripristinato.') ?? $this->property;
    }

    public function archive(): void
    {
        $this->authorize('archive', $this->property);
        $this->property = $this->command(fn () => app(SetPropertyLifecycle::class)->handle($this->actor(), $this->property, 'archive'), 'Immobile archiviato.') ?? $this->property;
    }
}; ?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:link :href="route('gestionale.properties.index')" wire:navigate>← Portafoglio</flux:link>
            <flux:heading size="xl" level="1" class="mt-2">{{ $property->title }}</flux:heading>
            <flux:text>{{ $property->address }}@if ($property->civic) {{ $property->civic }}@endif · {{ $property->city ?: $property->municipality?->name }}</flux:text>
        </div>
        <div class="flex gap-2">
            @can('update', $property)<flux:button variant="primary" :href="route('gestionale.properties.edit', $property)" wire:navigate>Modifica</flux:button>@endcan
            @if ($property->lifecycle_state === null)
                @can('archive', $property)<flux:button variant="ghost" wire:click="archive" wire:confirm="Archiviare questo immobile?">Archivia</flux:button>@endcan
            @else
                @can('archive', $property)<flux:button wire:click="restore">Ripristina</flux:button>@endcan
            @endif
        </div>
    </div>

    @if ($property->lifecycle_state)<flux:callout icon="archive-box">Scheda {{ $property->lifecycle_state === 'removed' ? 'rimossa dalla lista' : 'archiviata' }}. I dati e i collegamenti sono conservati.</flux:callout>@endif
    <div class="grid gap-4 xl:grid-cols-3">
        <flux:card class="flex flex-col gap-3 xl:col-span-2">
            <div class="flex items-center justify-between"><flux:heading size="lg">Scheda commerciale</flux:heading><flux:badge>{{ $property->status }}</flux:badge></div>
            <dl class="grid gap-3 sm:grid-cols-2">
                <div><dt class="text-sm text-zinc-500">Contratto</dt><dd>{{ $property->features['operation'] ?? 'Non indicato' }}</dd></div>
                <div><dt class="text-sm text-zinc-500">Prezzo / canone</dt><dd>{{ isset($property->features['price']) ? number_format((float) $property->features['price'], 0, ',', '.').' €' : 'Non indicato' }}</dd></div>
                <div><dt class="text-sm text-zinc-500">Superficie</dt><dd>{{ isset($property->features['area']) ? number_format((float) $property->features['area'], 0, ',', '.').' m²' : 'Non indicata' }}</dd></div>
                <div><dt class="text-sm text-zinc-500">Zona</dt><dd>{{ $property->zone ?: 'Non indicata' }}</dd></div>
                <div><dt class="text-sm text-zinc-500">Locali</dt><dd>{{ $property->features['rooms'] ?? 'Non indicati' }}</dd></div>
                <div><dt class="text-sm text-zinc-500">Camere e bagni</dt><dd>{{ $property->features['bedrooms'] ?? '—' }} / {{ $property->features['bathrooms'] ?? '—' }}</dd></div>
            </dl>
            @if ($property->description)<div><flux:heading size="sm">Descrizione</flux:heading><flux:text class="whitespace-pre-line">{{ $property->description }}</flux:text></div>@endif
            @if ($property->strengths)<div><flux:heading size="sm">Punti di forza</flux:heading><flux:text class="whitespace-pre-line">{{ $property->strengths }}</flux:text></div>@endif
            @if ($property->internal_notes)<div><flux:heading size="sm">Note interne</flux:heading><flux:text class="whitespace-pre-line">{{ $property->internal_notes }}</flux:text></div>@endif
        </flux:card>

        <flux:card class="flex flex-col gap-3">
            <flux:heading size="lg">Incarico</flux:heading>
            <flux:text>{{ $property->mandate['type'] ?? 'Tipo non indicato' }}</flux:text>
            <flux:text size="sm">Dal {{ $property->mandate['start'] ?? '—' }} al {{ $property->mandate['end'] ?? '—' }}</flux:text>
            <flux:heading size="lg" class="mt-4">Pubblicazione</flux:heading>
            <flux:text>{{ $property->publication['status'] ?? 'Non pubblicato' }}</flux:text>
            @if (! empty($property->publication['url']))<flux:link :href="$property->publication['url']" target="_blank" rel="noopener noreferrer">Apri annuncio</flux:link>@endif
            <flux:callout variant="secondary"><flux:callout.text>Le azioni del Gestionale non pubblicano automaticamente sui portali.</flux:callout.text></flux:callout>
        </flux:card>
    </div>

    <flux:card class="flex flex-col gap-3">
        <flux:heading size="lg">Unità catastali collegate</flux:heading>
        @forelse ($property->units as $link)
            @php($unit = $link->cadastralUnit)
            <div class="flex flex-wrap justify-between gap-2 border-b border-zinc-200 py-2 last:border-0">
                <flux:text>{{ $unit?->parcel?->municipality?->name ?? 'Comune non indicato' }} · Foglio {{ $unit?->parcel?->sheet ?? '—' }} · Particella {{ $unit?->parcel?->number ?? '—' }} · Sub. {{ $unit?->subalterno ?? '—' }}</flux:text>
                <flux:badge size="sm">{{ $link->role ?: 'Unità collegata' }}</flux:badge>
            </div>
        @empty
            <flux:text>Nessuna unità catastale collegata. Puoi esplorare il catalogo da <flux:link :href="route('trova')">Trova</flux:link>.</flux:text>
        @endforelse
    </flux:card>

    <flux:card class="flex flex-col gap-3">
        <flux:heading size="lg">Richieste compatibili</flux:heading>
        @forelse ($property->matches as $match)
            <div class="flex flex-wrap items-center gap-3 border-b border-zinc-200 py-2 last:border-0">
                <flux:link class="flex-1" :href="route('gestionale.requests.show', $match->propertyRequest)" wire:navigate>{{ $match->propertyRequest?->title ?? 'Richiesta non disponibile' }} · {{ $match->propertyRequest?->contact?->display_name }}</flux:link>
                <flux:badge>{{ $match->status }}</flux:badge>
                @if ($match->score !== null)<flux:text size="sm">{{ number_format((float) $match->score, 0) }}%</flux:text>@endif
            </div>
        @empty
            <flux:text>Non ci sono abbinamenti salvati per questo immobile.</flux:text>
        @endforelse
    </flux:card>
</div>
