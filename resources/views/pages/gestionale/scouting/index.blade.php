<?php

use App\Gestionale\Audit;
use App\Gestionale\CurrentAgency;
use App\Models\AgencyMembership;
use App\Models\Municipality;
use App\Models\ScoutingZone;
use App\Models\ScoutingZoneAssignment;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::gestionale'), Title('Mappa e zone')] class extends Component {
    public string $name = '';
    public string $notes = '';
    public array $municipalities = [];
    public array $scouts = [];

    #[Computed]
    public function membership() { return app(CurrentAgency::class)->membership(); }

    #[Computed]
    public function availableMunicipalities()
    {
        return Municipality::query()->whereHas('catalog')->with('province')->orderBy('name')->get();
    }

    #[Computed]
    public function members()
    {
        return AgencyMembership::query()->where('agency_id', $this->membership->agency_id)->active()->with('user')->orderBy('role')->get();
    }

    #[Computed]
    public function zones()
    {
        $query = ScoutingZone::query()->with(['assignments.user'])->where('agency_id', $this->membership->agency_id);
        if ($this->membership->role !== 'admin') $query->whereHas('assignments', fn ($q) => $q->where('user_id', $this->membership->user_id));
        return $query->orderByDesc('updated_at')->get();
    }

    public function save(): void
    {
        $membership = $this->membership;
        abort_unless($membership->isAdmin(), 403);
        $this->validate([
            'name' => ['required', 'string', 'max:160'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'municipalities' => ['required', 'array', 'min:1', 'max:30'],
            'municipalities.*' => ['integer', 'distinct', 'exists:municipalities,id'],
            'scouts' => ['required', 'array', 'min:1', 'max:100'],
            'scouts.*' => ['integer', 'distinct'],
        ]);
        $towns = Municipality::query()->whereIn('id', $this->municipalities)->get(['id', 'cadastral_code']);
        $members = AgencyMembership::query()->where('agency_id', $membership->agency_id)->active()->whereIn('user_id', $this->scouts)->get();
        if ($towns->count() !== count($this->municipalities) || $members->count() !== count($this->scouts)) {
            $this->addError('scouts', 'Scegli soltanto Comuni disponibili e operatori attivi della tua agenzia.');
            return;
        }

        DB::transaction(function () use ($membership, $towns, $members) {
            $zone = new ScoutingZone;
            $zone->forceFill([
                'agency_id' => $membership->agency_id,
                'operator_user_id' => $members->first()->user_id,
                'name' => trim($this->name), 'notes' => trim($this->notes) ?: null,
                'status' => 'Pianificata', 'starts_on' => today(),
                'municipalities' => $towns->pluck('cadastral_code')->values()->all(),
            ])->save();
            foreach ($members as $member) {
                (new ScoutingZoneAssignment)->forceFill(['agency_id' => $membership->agency_id, 'scouting_zone_id' => $zone->id, 'user_id' => $member->user_id])->save();
            }
            DB::update('UPDATE scouting_zones SET boundary = (SELECT ST_Multi(ST_CollectionExtract(ST_Union(boundary), 3)) FROM municipalities WHERE id IN ('.implode(',', array_fill(0, $towns->count(), '?')).')) WHERE agency_id = ? AND id = ?', [...$towns->pluck('id')->all(), $membership->agency_id, $zone->id]);
            app(Audit::class)->record('scouting-zone.create', $zone, ['municipalities' => $zone->municipalities, 'users' => $members->pluck('user_id')->all()]);
        });
        $this->reset('name', 'notes', 'municipalities', 'scouts');
        unset($this->zones);
        session()->flash('status', 'Zona di scouting assegnata.');
    }
}; ?>

<div class="flex flex-col gap-6">
    <div><flux:heading size="xl" level="1">Mappa e zone</flux:heading><flux:text class="mt-1">Assegna il territorio agli operatori. Le zone usano i confini comunali presenti nel catalogo.</flux:text></div>
    @if (session('status'))<flux:callout icon="check-circle">{{ session('status') }}</flux:callout>@endif
    @if ($this->membership->isAdmin())
        <flux:card>
            <form wire:submit="save" class="flex flex-col gap-4">
                <flux:heading size="lg">Crea una zona di scouting</flux:heading>
                <div class="grid gap-4 md:grid-cols-2"><flux:input wire:model="name" label="Nome della zona" placeholder="Es. Cuneo e frazioni" /><flux:textarea wire:model="notes" label="Indicazioni per gli operatori" rows="2" /></div>
                <label class="flex flex-col gap-1 text-sm font-medium">Comuni inclusi
                    <select multiple wire:model="municipalities" class="min-h-36 rounded-lg border border-zinc-300 bg-white p-2 text-sm">
                        @foreach ($this->availableMunicipalities as $municipality)<option value="{{ $municipality->id }}">{{ $municipality->name }} ({{ $municipality->cadastral_code }})</option>@endforeach
                    </select>
                    <span class="text-xs text-zinc-500">Usa Ctrl o ⌘ per selezionare più Comuni. Serve un’edizione catastale attiva.</span>
                </label>
                <label class="flex flex-col gap-1 text-sm font-medium">Operatori assegnati
                    <select multiple wire:model="scouts" class="min-h-28 rounded-lg border border-zinc-300 bg-white p-2 text-sm">
                        @foreach ($this->members as $member)<option value="{{ $member->user_id }}">{{ $member->user->name }} · {{ ['admin' => 'Responsabile', 'crm' => 'Segreteria', 'scout' => 'Operatore'][$member->role] }}</option>@endforeach
                    </select>
                </label>
                @error('municipalities')<flux:callout variant="danger">{{ $message }}</flux:callout>@enderror
                @error('scouts')<flux:callout variant="danger">{{ $message }}</flux:callout>@enderror
                <div><flux:button variant="primary" type="submit">Assegna zona</flux:button></div>
            </form>
        </flux:card>
    @endif
    <div class="grid gap-4 md:grid-cols-2">
        @forelse ($this->zones as $zone)
            <flux:card class="flex flex-col gap-3"><div class="flex items-center justify-between"><flux:heading size="lg">{{ $zone->name }}</flux:heading><flux:badge>{{ $zone->status }}</flux:badge></div>
                <flux:text>{{ $zone->notes ?: 'Nessuna nota operativa.' }}</flux:text>
                <div class="flex flex-wrap gap-2">@foreach ($zone->municipalities ?? [] as $code)<flux:badge size="sm">{{ $this->availableMunicipalities->firstWhere('cadastral_code', $code)?->name ?? $code }}</flux:badge>@endforeach</div>
                <flux:text size="sm">Operatori: {{ $zone->assignments->map(fn ($assignment) => $assignment->user?->name)->filter()->join(', ') }}</flux:text>
                @if ($zone->boundary)<flux:callout icon="map">Per questa zona sono disponibili i confini territoriali dei Comuni selezionati.</flux:callout>@else<flux:callout variant="secondary">Confini comunali non disponibili nei dati caricati.</flux:callout>@endif
            </flux:card>
        @empty
            <flux:card><flux:text>Nessuna zona assegnata. Un responsabile può crearne una scegliendo Comuni e operatori.</flux:text></flux:card>
        @endforelse
    </div>
</div>
