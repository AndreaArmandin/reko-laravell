<?php

use App\Gestionale\CurrentAgency;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::gestionale'), Title('Archivio catastale')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $category = '';
    public ?int $municipalityId = null;

    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedCategory(): void { $this->resetPage(); }
    public function updatedMunicipalityId(): void { $this->resetPage(); }

    #[Computed]
    public function municipalities()
    {
        return DB::table('municipalities as m')->join('municipality_catalogs as mc', 'mc.municipality_id', '=', 'm.id')
            ->orderBy('m.name')->get(['m.id', 'm.name', 'm.cadastral_code']);
    }

    #[Computed]
    public function units()
    {
        $membership = app(CurrentAgency::class)->membership();
        abort_unless(in_array($membership?->role, ['admin', 'scout'], true), 403);

        $query = DB::table('cadastral_unit_versions as v')
            ->join('catalog_releases as cr', 'cr.id', '=', 'v.catalog_release_id')
            ->join('municipality_catalogs as mc', fn ($join) => $join->on('mc.catalog_release_id', '=', 'v.catalog_release_id'))
            ->join('municipalities as m', 'm.id', '=', 'mc.municipality_id')
            ->join('cadastral_units as cu', 'cu.id', '=', 'v.cadastral_unit_id')
            ->join('parcels as p', 'p.id', '=', 'cu.parcel_id')
            ->where('v.status', 'eligible')
            ->where('cr.status', 'active')
            ->where('p.cadastral_kind', 'F');

        if ($membership->role === 'scout') {
            $query->where(function ($visible) use ($membership) {
                $visible->whereExists(fn ($assignment) => $assignment->selectRaw('1')->from('scouting_assignments as sa')
                    ->whereColumn('sa.cadastral_unit_id', 'cu.id')->where('sa.agency_id', $membership->agency_id)->where('sa.user_id', $membership->user_id))
                    ->orWhereExists(fn ($zone) => $zone->selectRaw('1')->from('scouting_zone_assignments as sza')
                        ->join('scouting_zones as sz', 'sz.id', '=', 'sza.scouting_zone_id')
                        ->where('sza.agency_id', $membership->agency_id)->where('sza.user_id', $membership->user_id)
                        ->whereColumn('sz.agency_id', 'sza.agency_id')
                        ->whereRaw("sz.municipalities @> jsonb_build_array(m.cadastral_code)"));
            });
        }
        if ($this->municipalityId) $query->where('m.id', $this->municipalityId);
        if ($this->category !== '') $query->where('v.category', $this->category);
        $term = trim($this->search);
        if ($term !== '') $query->where(fn ($q) => $q->where('v.address_raw', 'ilike', "%{$term}%")
            ->orWhere('p.sheet', 'ilike', "%{$term}%")->orWhere('p.number', 'ilike', "%{$term}%")
            ->orWhere('cu.subalterno', 'ilike', "%{$term}%")->orWhere('m.name', 'ilike', "%{$term}%"));

        return $query->orderBy('m.name')->orderBy('p.sheet')->orderBy('p.number')->orderBy('cu.subalterno')
            ->paginate(30, ['cu.id as unit_id', 'm.name as municipality', 'm.cadastral_code', 'p.section', 'p.sheet', 'p.number', 'cu.subalterno', 'v.category', 'v.address_raw', 'v.consistency', 'v.consistency_unit']);
    }
}; ?>

<div class="flex flex-col gap-5">
    <div><flux:heading size="xl" level="1">Archivio catastale</flux:heading><flux:text class="mt-1">Unità dei Comuni con un’edizione SISTER pubblicata. L’archivio descrive i dati catastali, non certifica proprietà o destinazione d’uso.</flux:text></div>
    <flux:card class="grid gap-4 md:grid-cols-3">
        <flux:input wire:model.live.debounce.300ms="search" label="Cerca Comune, indirizzo, foglio, particella o subalterno" />
        <flux:select wire:model.live="municipalityId" label="Comune"><flux:select.option value="">Tutti i Comuni disponibili</flux:select.option>@foreach ($this->municipalities as $municipality)<flux:select.option :value="$municipality->id">{{ $municipality->name }} ({{ $municipality->cadastral_code }})</flux:select.option>@endforeach</flux:select>
        <flux:input wire:model.live="category" label="Categoria catastale" placeholder="A/2" />
    </flux:card>
    <div class="overflow-x-auto rounded-xl border border-zinc-200 bg-white">
        <table class="w-full min-w-[850px] text-left text-sm">
            <thead class="bg-zinc-50 text-zinc-600"><tr><th class="p-3">Comune</th><th class="p-3">Identificativo catastale</th><th class="p-3">Categoria</th><th class="p-3">Indirizzo censito</th><th class="p-3">Consistenza</th></tr></thead>
            <tbody>
                @forelse ($this->units as $unit)
                    <tr class="border-t border-zinc-100"><td class="p-3">{{ $unit->municipality }}</td><td class="p-3">Foglio {{ $unit->sheet }} · Particella {{ $unit->number }} · Sub. {{ $unit->subalterno ?: '—' }}</td><td class="p-3">{{ $unit->category ?: '—' }}</td><td class="p-3">{{ \App\Trova\Presentation::address((string) $unit->address_raw) ?: 'Non indicato' }}</td><td class="p-3">{{ $unit->consistency === null ? '—' : \App\Trova\Presentation::measure($unit->consistency, $unit->consistency_unit) }}</td></tr>
                @empty
                    <tr><td colspan="5" class="p-6 text-center text-zinc-500">Nessuna unità trovata nei dati pubblicati per i filtri selezionati.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $this->units->links() }}
</div>
