<?php

use App\Models\Parcel;
use App\Trova\Categories;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Particella')] class extends Component {
    public Parcel $parcel;
    public int $releaseId;

    public function mount(Parcel $parcel): void
    {
        $this->parcel = $parcel->load('municipality.catalog.catalogRelease');

        // Solo particelle del catalogo attivo del loro Comune, come nella ricerca
        $releaseId = $parcel->municipality->catalog?->catalog_release_id;
        abort_if($releaseId === null || $this->units === [], 404);
        $this->releaseId = $releaseId;
    }

    // Unità visibili della particella nell'edizione attiva (escluse e categorie B/E mai, come nella ricerca)
    #[Computed]
    public function units(): array
    {
        $releaseId = $this->parcel->municipality->catalog?->catalog_release_id;

        return DB::select(
            "SELECT u.subalterno sub, v.category, v.consistency, v.consistency_unit, v.address_raw
             FROM cadastral_units u
             JOIN cadastral_unit_versions v ON v.cadastral_unit_id = u.id AND v.catalog_release_id = ?
             WHERE u.parcel_id = ? AND v.status = 'eligible'
               AND v.category NOT LIKE 'B/%' AND v.category NOT LIKE 'E/%'
             ORDER BY coalesce(substring(u.subalterno from '^\\d+')::int, 0), u.subalterno, u.id",
            [$releaseId, $this->parcel->id],
        );
    }

    // Sagoma (unione degli edifici collegati), area a terra e punto sulla mappa
    #[Computed]
    public function place(): object
    {
        return DB::selectOne(
            "SELECT
                (SELECT ST_AsGeoJSON(ST_Multi(ST_Union(bv.footprint)))::json FROM building_parcel_links l
                 JOIN building_versions bv ON bv.building_id = l.building_id AND bv.catalog_release_id = l.catalog_release_id
                 WHERE l.parcel_id = ? AND l.catalog_release_id = ?) footprint,
                (SELECT sum(bv.area_sqm) FROM building_parcel_links l
                 JOIN building_versions bv ON bv.building_id = l.building_id AND bv.catalog_release_id = l.catalog_release_id
                 WHERE l.parcel_id = ? AND l.catalog_release_id = ?) area,
                (SELECT ST_Y(location) FROM parcel_search_points WHERE parcel_id = ? AND catalog_release_id = ?) latitude,
                (SELECT ST_X(location) FROM parcel_search_points WHERE parcel_id = ? AND catalog_release_id = ?) longitude,
                (SELECT source FROM parcel_search_points WHERE parcel_id = ? AND catalog_release_id = ?) point_source",
            array_merge(...array_fill(0, 5, [$this->parcel->id, $this->releaseId])),
        );
    }

    // Per la mappa: la sagoma se c'è, altrimenti la puntina
    #[Computed]
    public function mapFeatures(): array
    {
        $geometry = $this->place->footprint !== null
            ? json_decode($this->place->footprint, true)
            : ($this->place->latitude !== null ? ['type' => 'Point', 'coordinates' => [$this->place->longitude, $this->place->latitude]] : null);

        return $geometry === null ? [] : [['type' => 'Feature', 'geometry' => $geometry, 'properties' => []]];
    }

    // Piano scritto in fondo all'indirizzo SISTER ("… Piano 2")
    public function floor(?string $address): string
    {
        return $address !== null && preg_match('/\bPiano\s+(\S+)$/i', $address, $m) ? $m[1] : '—';
    }

    public function value(mixed $value): string
    {
        return $value === null ? '—' : str_replace('.', ',', (string) (float) $value);
    }
}; ?>


<div class="mx-auto max-w-screen-2xl space-y-6 p-6">
    <flux:button icon="arrow-left" variant="ghost" onclick="history.back()">Torna ai risultati</flux:button>

    {{-- 1. Intestazione --}}
    <div class="space-y-2">
        <flux:heading size="xl">
            {{ preg_replace('/\s+Piano\s+\S+$/i', '', $this->units[0]->address_raw ?? '') ?: 'Indirizzo non disponibile' }}
        </flux:heading>
        <flux:text>
            {{ $parcel->municipality->name }}
            @if ($parcel->section !== '') · Sez. {{ $parcel->section }} @endif
            · Fg. {{ $parcel->sheet }} · Part. {{ $parcel->number }}
        </flux:text>
        <div class="flex flex-wrap gap-2">
            @foreach (collect($this->units)->pluck('category')->unique()->sort() as $category)
                <flux:badge size="sm">{{ $category }} · {{ Categories::label($category) }}</flux:badge>
            @endforeach
        </div>
        <flux:text>
            {{ count($this->units) }} unità
            @php($withoutSub = collect($this->units)->whereNull('sub')->count())
            @if ($withoutSub > 0)
                · {{ $withoutSub }} senza subalterno
            @else
                · tutte con subalterno
            @endif
        </flux:text>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        {{-- 3. Unità --}}
        <div class="min-w-0">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Sub</flux:table.column>
                    <flux:table.column>Categoria</flux:table.column>
                    <flux:table.column>Consistenza</flux:table.column>
                    <flux:table.column>Piano</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($this->units as $unit)
                        <flux:table.row>
                            <flux:table.cell>{{ $unit->sub ?? 'Senza subalterno' }}</flux:table.cell>
                            <flux:table.cell>{{ $unit->category }} · {{ Categories::label($unit->category) }}</flux:table.cell>
                            <flux:table.cell>{{ $this->value($unit->consistency) }} {{ $unit->consistency_unit }}</flux:table.cell>
                            <flux:table.cell>{{ $this->floor($unit->address_raw) }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>

        <div class="space-y-4">
            {{-- 2. Mappa: lo stesso componente della ricerca --}}
            @if ($this->mapFeatures !== [])
                <div wire:ignore x-data="trovaMap(@js($this->mapFeatures))"
                     class="h-[420px] overflow-hidden rounded-xl border border-zinc-200">
                    <div x-ref="map" class="h-full w-full"></div>
                </div>
                <flux:link href="https://www.google.com/maps/search/?api=1&query={{ $this->place->latitude }},{{ $this->place->longitude }}" external>
                    Apri Google Maps
                </flux:link>
            @else
                <flux:callout icon="map-pin" heading="Posizione sulla mappa non disponibile." />
            @endif

            {{-- 4. Edificio --}}
            @if ($this->place->area !== null)
                <flux:text>
                    Superficie a terra dell'edificio: <strong>{{ $this->value(round($this->place->area)) }} m²</strong>
                    (impronta della sagoma, non la superficie delle unità)
                </flux:text>
            @endif

            {{-- 5. Fonte e verifiche --}}
            <flux:callout variant="secondary" icon="information-circle" heading="Fonte e verifiche">
                <flux:callout.text>
                    Dati dell'edizione {{ $parcel->municipality->catalog->catalogRelease->code }}
                    · posizione: {{ $this->place->point_source ?? 'non disponibile' }}.
                    È un candidato da verificare, non un immobile in vendita.
                </flux:callout.text>
            </flux:callout>
        </div>
    </div>
</div>