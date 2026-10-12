<?php

use App\Models\ImportRun;
use App\Models\Municipality;
use App\Models\TerritorialProvince;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
new #[Title('Catalogo')] class extends Component {

    use WithPagination;
    public string $search = '';

    // Aggiungi Comune
    public bool $adding = false;
    public string $code = '';
    public string $name = '';
    public string $provinceId = '';
    public string $lookup = '';

    // Check di controllo accessi per l'amministratore
    public function boot(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    #[Computed]
    public function busy(): bool
    {
        return ImportRun::query()->whereIn('status', ['queued', 'running'])->exists();
    }

    #[Computed]
    public function provinces()
    {
        return TerritorialProvince::query()->orderBy('name')->get(['id', 'name', 'abbreviation']);
    }

    /** Comuni con edizione pubblicata, unità, quota di particelle sulla mappa e bozza in corso. */
    #[Computed]
    public function municipalities()
    {
        $term = trim($this->search);

        return DB::table('municipalities as m')
            ->leftJoin('territorial_provinces as tp', 'tp.id', '=', 'm.territorial_province_id')
            ->leftJoin('municipality_catalogs as mc', 'mc.municipality_id', '=', 'm.id')
            ->leftJoin('catalog_releases as r', 'r.id', '=', 'mc.catalog_release_id')
            ->leftJoin('catalog_releases as d', fn ($j) => $j->on('d.municipality_id', '=', 'm.id')->where('d.status', 'draft'))
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('m.name', 'ilike', '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%')->orWhere('m.cadastral_code', strtoupper($term))))
            ->selectRaw('m.id, m.name, m.cadastral_code, tp.abbreviation AS province, r.code AS release_code, mc.activated_at,
                d.code AS draft_code,
                (SELECT status FROM import_runs WHERE catalog_release_id = d.id ORDER BY id DESC LIMIT 1) AS draft_status,
                (SELECT count(*) FROM cadastral_unit_versions v WHERE v.catalog_release_id = r.id) AS units,
                (SELECT count(DISTINCT u.parcel_id) FROM cadastral_unit_versions v JOIN cadastral_units u ON u.id = v.cadastral_unit_id WHERE v.catalog_release_id = r.id) AS parcels,
                (SELECT count(*) FROM parcel_search_points s WHERE s.catalog_release_id = r.id) AS located')
            ->orderBy('m.name')->paginate(100);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /** Suggerimenti dall'elenco nazionale ISTAT (reko:import-istat), per nome o codice catastale. */
    #[Computed]
    public function suggestions()
    {
        $term = trim($this->lookup);
        if (mb_strlen($term) < 2 || ! \Illuminate\Support\Facades\Schema::hasTable('territorial_municipalities')) {
            return collect();
        }
        $like = str_replace(['%', '_'], ['\%', '\_'], $term);

        return DB::table('territorial_municipalities as t')->leftJoin('territorial_provinces as p', 'p.id', '=', 't.territorial_province_id')
            ->where(fn ($w) => $w->where('t.name', 'ilike', $like.'%')->orWhere('t.name', 'ilike', '% '.$like.'%')->orWhere('t.cadastral_code', strtoupper($term)))
            ->orderByRaw('t.cadastral_code = ? DESC, t.name ilike ? DESC, length(t.name), t.name', [strtoupper($term), $like.'%'])
            ->limit(10)->get(['t.name', 't.cadastral_code', 't.territorial_province_id', 'p.abbreviation']);
    }

    public function pick(string $code): void
    {
        $m = DB::table('territorial_municipalities')->where('cadastral_code', strtoupper($code))->first();
        abort_unless($m !== null, 422);
        $this->name = $m->name;
        $this->code = $m->cadastral_code;
        $this->provinceId = (string) ($m->territorial_province_id ?? '');
        $this->lookup = '';
        $this->resetErrorBag();
    }

    public function addMunicipality(): void
    {
        $this->code = strtoupper(trim($this->code));
        $this->validate([
            'code' => ['required', 'regex:/^[A-Z]\d{3}$/', 'unique:municipalities,cadastral_code'],
            'name' => ['required', 'string', 'max:255'],
            'provinceId' => ['nullable', 'integer', 'exists:territorial_provinces,id'],
        ], [
            'code.required' => 'Indica il codice catastale del Comune.',
            'code.regex' => 'Il codice catastale ha una lettera e tre cifre, per esempio D205.',
            'code.unique' => 'Questo Comune c’è già: cercalo nell’elenco.',
            'name.required' => 'Indica il nome del Comune.',
        ]);

        $municipality = Municipality::query()->create([
            'cadastral_code' => $this->code,
            'name' => trim($this->name),
            'territorial_province_id' => $this->provinceId !== '' ? (int) $this->provinceId : null,
        ]);

        $this->redirectRoute('admin.catalog.show', $municipality, navigate: true);
    }
};
?>

<div class="mx-auto max-w-5xl space-y-8 p-6" @if ($this->busy) wire:poll.3s @endif>
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl">Catalogo</flux:heading>
            <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">
                Ogni Comune ha un’edizione pubblicata, quella che usa la ricerca. Apri un Comune per caricare una nuova edizione:
                si prepara come bozza e sostituisce la vecchia solo quando premi «Pubblica».
            </p>
        </div>
        <flux:button variant="primary" icon="plus" wire:click="$toggle('adding')">Aggiungi Comune</flux:button>
    </div>

    @if ($adding)
        <form wire:submit="addMunicipality" class="space-y-4 rounded-xl border p-5">
            <h2 class="font-semibold">Nuovo Comune</h2>
            <div class="relative">
                <flux:input wire:model.live.debounce.250ms="lookup" icon="magnifying-glass" label="Cerca nell’elenco ISTAT" placeholder="Nome o codice catastale, es. Torino o L219" autocomplete="off" />
                @if ($this->suggestions->isNotEmpty())
                    <ul class="absolute z-10 mt-1 w-full overflow-hidden rounded-lg border bg-white shadow-lg dark:bg-zinc-800" role="listbox" aria-label="Comuni suggeriti">
                        @foreach ($this->suggestions as $s)
                            <li wire:key="sug-{{ $s->cadastral_code }}">
                                <button type="button" wire:click="pick('{{ $s->cadastral_code }}')" class="w-full px-3 py-2 text-left text-sm hover:bg-zinc-100 dark:hover:bg-zinc-700" role="option">
                                    {{ $s->name }}{{ $s->abbreviation ? ' ('.$s->abbreviation.')' : '' }} <span class="text-zinc-500">· {{ $s->cadastral_code }}</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @elseif (mb_strlen(trim($lookup)) >= 2)
                    <p class="mt-1 text-sm text-zinc-500">Nessun Comune trovato: compila i campi qui sotto a mano.</p>
                @endif
            </div>
            <div class="grid gap-4 sm:grid-cols-3">
                <flux:input wire:model="name" label="Nome del Comune" placeholder="Torino" />
                <flux:input wire:model="code" label="Codice catastale" description="Una lettera e tre cifre, es. L219" maxlength="4" />
                @if ($this->provinces->isNotEmpty())
                    <flux:select wire:model="provinceId" label="Provincia">
                        <flux:select.option value="">—</flux:select.option>
                        @foreach ($this->provinces as $p)
                            <flux:select.option value="{{ $p->id }}">{{ $p->name }}{{ $p->abbreviation ? ' ('.$p->abbreviation.')' : '' }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif
            </div>
            @foreach (['name', 'code', 'provinceId'] as $field)
                @error($field) <p class="text-sm text-red-600" role="alert">{{ $message }}</p> @enderror
            @endforeach
            <div class="flex gap-2">
                <flux:button type="submit" variant="primary">Crea e apri</flux:button>
                <flux:button wire:click="$set('adding', false)">Annulla</flux:button>
            </div>
        </form>
    @endif

    <div class="space-y-3">
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Cerca per nome o codice catastale" aria-label="Cerca Comune" />

        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full text-left text-sm">
                <thead class="border-b bg-zinc-50 dark:bg-zinc-900">
                    <tr><th class="p-3">Comune</th><th class="p-3">Edizione pubblicata</th><th class="p-3">Unità</th><th class="p-3">Particelle sulla mappa</th><th class="p-3">Bozza</th><th class="p-3"></th></tr>
                </thead>
                <tbody>
                    @forelse ($this->municipalities as $m)
                        <tr class="border-b last:border-0" wire:key="m-{{ $m->id }}">
                            <td class="p-3 font-medium">{{ $m->name }} <span class="text-zinc-500">({{ $m->cadastral_code }}{{ $m->province ? ' · '.$m->province : '' }})</span></td>
                            <td class="p-3">
                                @if ($m->release_code)
                                    {{ $m->release_code }} <span class="text-zinc-500">· {{ \Illuminate\Support\Carbon::parse($m->activated_at)->format('d/m/Y') }}</span>
                                @else
                                    <span class="text-zinc-500">Nessuna</span>
                                @endif
                            </td>
                            <td class="p-3">{{ number_format((int) $m->units, 0, ',', '.') }}</td>
                            <td class="p-3">{{ $m->parcels > 0 ? round($m->located * 100 / $m->parcels).'%' : '—' }}</td>
                            <td class="p-3">
                                @if ($m->draft_code)
                                    <span class="rounded-full bg-amber-100 px-2 py-0.5 text-amber-900">In corso{{ in_array($m->draft_status, ['queued', 'running'], true) ? ' · import…' : '' }}</span>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="p-3 text-right"><flux:button size="sm" :href="route('admin.catalog.show', $m->id)" wire:navigate>Apri</flux:button></td>
                        </tr>
                    @empty
                        <tr><td class="p-3" colspan="6">Nessun Comune{{ $search !== '' ? ' trovato' : '' }}. Usa «Aggiungi Comune».</td></tr>
                    @endforelse

                    <div class="mt-4">
                        {{ $this->municipalities->links() }}
                    </div>
                </tbody>
            </table>
        </div>
    </div>
</div>
