<?php

use App\Jobs\ImportBuildingGeometry;
use App\Jobs\ImportParcelGeometry;
use App\Jobs\ImportSisterCatalog;
use App\Models\CatalogRelease;
use App\Models\ImportIssue;
use App\Models\ImportRun;
use App\Models\Municipality;
use App\Models\TerritorialProvince;
use App\Trova\BuildingGeometryImporter;
use App\Trova\CatalogImporter;
use App\Trova\GeoJsonSource;
use App\Trova\ParcelGeometryImporter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Catalogo del Comune')] class extends Component {
    use WithFileUploads;

    public Municipality $municipality;

    /** Passo della procedura: sister · parcels · buildings · summary ('' = quello giusto per lo stato della bozza) */
    public string $step = '';

    // Passo 1 · file SISTER
    public bool $newEdition = false;
    public string $note = '';
    public $file = null;

    // Passi 2 e 3 · sagome (particelle o fabbricati)
    public $geoFile = null;
    /** @var array<string, string> campo => valore di esempio */
    /** @var array{reference: string, sheet: string, parcel: string, section: string, id: string} */
    public ?array $check = null;
    public bool $analyzed = false;

    public bool $confirmRisk = false;
    public string $provinceId = '';

    public function boot(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    public function mount(Municipality $municipality): void
    {
        $this->municipality = $municipality;
        $this->provinceId = (string) ($municipality->territorial_province_id ?? '');
    }

    #[Computed]
    public function maxMb(): int
    {
        $bytes = fn (string $v) => (int) $v * (['k' => 1024, 'm' => 1048576, 'g' => 1073741824][strtolower(substr(trim($v), -1))] ?? 1);
        $php = min($bytes((string) ini_get('upload_max_filesize')) ?: PHP_INT_MAX, $bytes((string) ini_get('post_max_size')) ?: PHP_INT_MAX);

        return max(1, min(100, intdiv($php, 1048576)));
    }

    #[Computed]
    public function draft(): ?CatalogRelease
    {
        return CatalogRelease::query()->where('municipality_id', $this->municipality->id)->where('status', 'draft')->latest('id')->first();
    }

    /** Ultimo import di ogni tipo della bozza. */
    #[Computed]
    public function runs(): array
    {
        if ($this->draft === null) {
            return ['sister' => null, 'parcels' => null, 'buildings' => null];
        }
        $runs = ImportRun::query()->where('catalog_release_id', $this->draft->id)->orderByDesc('id')->get();

        return [
            'sister' => $runs->firstWhere('source_name', 'sister-catalog'),
            'parcels' => $runs->firstWhere('source_name', 'parcel-geometry'),
            'buildings' => $runs->firstWhere('source_name', BuildingGeometryImporter::SOURCE),
        ];
    }

    #[Computed]
    public function ready(): bool
    {
        return $this->runs['sister']?->status === 'done';
    }

    #[Computed]
    public function busy(): bool
    {
        return collect($this->runs)->filter()->contains(fn (ImportRun $r) => in_array($r->status, ['queued', 'running'], true));
    }

    #[Computed]
    public function stuck(): bool
    {
        return collect($this->runs)->filter()->contains(fn (ImportRun $r) => $r->status === 'queued' && $r->created_at < now()->subSeconds(20));
    }

    #[Computed]
    public function coverage(): ?array
    {
        return $this->ready ? CatalogImporter::coverage($this->draft->id) : null;
    }

    #[Computed]
    public function buildings(): int
    {
        return $this->draft ? DB::table('building_versions')->where('catalog_release_id', $this->draft->id)->count() : 0;
    }

    #[Computed]
    public function warnings(): array
    {
        return $this->ready ? CatalogImporter::publishWarnings($this->draft) : [];
    }

    #[Computed]
    public function issues()
    {
        $ids = collect($this->runs)->filter()->pluck('id');

        return ImportIssue::query()->whereIn('import_run_id', $ids)->orderBy('id')->limit(12)->get();
    }

    #[Computed]
    public function editions()
    {
        $releases = CatalogRelease::query()->where('municipality_id', $this->municipality->id)->orderByDesc('id')->get();
        $units = DB::table('cadastral_unit_versions')->whereIn('catalog_release_id', $releases->pluck('id'))->selectRaw('catalog_release_id as id, count(*) as n')->groupBy('catalog_release_id')->pluck('n', 'id');

        return $releases->map(fn (CatalogRelease $r) => ['release' => $r, 'units' => (int) ($units[$r->id] ?? 0)]);
    }

    #[Computed]
    public function provinces()
    {
        return TerritorialProvince::query()->orderBy('name')->get(['id', 'name', 'abbreviation']);
    }

    /** Il passo mostrato: quello scelto, oppure il primo che resta da fare. */
    public function currentStep(): string
    {
        if ($this->draft === null) {
            return 'sister';
        }
        if ($this->step !== '' && ($this->ready || $this->step === 'sister')) {
            return $this->step;
        }
        if (! $this->ready) {
            return 'sister';
        }

        return $this->runs['parcels']?->status === 'done' ? ($this->runs['buildings'] ? 'summary' : 'buildings') : 'parcels';
    }

    public function stepDone(string $step): bool
    {
        return match ($step) {
            'sister' => $this->ready,
            'parcels', 'buildings' => $this->runs[$step]?->status === 'done',
            default => false,
        };
    }

    public function goTo(string $step): void
    {
        abort_unless(in_array($step, ['sister', 'parcels', 'buildings', 'summary'], true), 422);
        $this->step = $step;
        $this->resetGeo();
    }

    private function resetGeo(): void
    {
        $this->reset('geoFile', 'check', 'analyzed');
        $this->resetErrorBag();
    }

    public function updatedProvinceId(): void
    {
        $id = $this->provinceId !== '' ? (int) $this->provinceId : null;
        abort_unless($id === null || TerritorialProvince::query()->whereKey($id)->exists(), 422);
        $this->municipality->update(['territorial_province_id' => $id]);
    }

    // ── Passo 1 · SISTER ────────────────────────────────────────────

    /** Lo stesso file dell'edizione pubblicata: probabilmente non serve una nuova edizione. */
    #[Computed]
    public function sisterDuplicate(): bool
    {
        if (! $this->file || ! method_exists($this->file, 'getRealPath') || ! is_file((string) $this->file->getRealPath())) {
            return false;
        }
        $activeId = DB::table('municipality_catalogs')->where('municipality_id', $this->municipality->id)->value('catalog_release_id');

        return $activeId !== null && ImportRun::query()->where('catalog_release_id', $activeId)->where('source_name', 'sister-catalog')
            ->where('sha256', hash_file('sha256', (string) $this->file->getRealPath()))->exists();
    }

    public function importSister(): void
    {
        $this->validate([
            'note' => ['nullable', 'string', 'max:500'],
            'file' => ['required', 'file', 'extensions:txt,csv,tsv', 'max:'.($this->maxMb * 1024)],
        ], [
            'file.required' => 'Carica il file SISTER.',
            'file.extensions' => 'Il file deve essere un .txt, .csv o .tsv.',
            'file.max' => "Il file non può superare {$this->maxMb} MB.",
        ]);

        $stored = $this->file->storeAs('catalog-imports', now()->format('YmdHis').'-'.Str::slug(pathinfo($this->file->getClientOriginalName(), PATHINFO_FILENAME)).'.txt', 'local');
        try {
            $run = app(CatalogImporter::class)->prepare($this->municipality->cadastral_code, $this->municipality->name, (string) $stored, $this->file->getClientOriginalName(), $this->note);
        } catch (\RuntimeException $e) {
            Storage::disk('local')->delete((string) $stored);
            $this->addError('file', $e->getMessage());

            return;
        }

        ImportSisterCatalog::dispatch($run->id);
        $this->reset('note', 'file', 'newEdition', 'step');
        $this->refresh();
        session()->flash('status', 'Import del file SISTER avviato: la bozza si riempie qui sotto.');
    }

    // ── Passi 2 e 3 · sagome ────────────────────────────────────────

    /** Appena caricato il GeoJSON: campi trovati, abbinamento proposto, sistema di riferimento e prova su un campione. */
    public function updatedGeoFile(): void
    {
        $this->resetErrorBag('geoFile');
        $this->analyzed = false;
        $this->validateOnly('geoFile', ['geoFile' => ['file', 'extensions:geojson,json', 'max:'.($this->maxMb * 1024)]], [
            'geoFile.extensions' => 'Il file deve essere un .geojson o un .json.',
            'geoFile.max' => "Il file non può superare {$this->maxMb} MB.",
        ]);
        $path = (string) $this->geoFile->getRealPath();
        try {
            $sample = GeoJsonSource::sample($path, 50);
        } catch (\RuntimeException $e) {
            $this->addError('geoFile', $e->getMessage());

            return;
        }
        if ($sample === []) {
            $this->addError('geoFile', 'Il file non contiene sagome (serve un GeoJSON FeatureCollection).');

            return;
        }
        $this->analyzed = true;
        $this->runCheck($sample);
    }

    /** Campi e sistema di riferimento letti dal file, senza scelte dell'utente. @return array{map: array<string, string>, epsg: ?int} */
    private function detected(array $sample): array
    {
        $map = array_filter(GeoJsonSource::detect(array_keys(GeoJsonSource::properties($sample))), fn ($v) => $v !== '');
        if ($this->currentStep() === 'parcels') {
            unset($map['id']);
        }

        return ['map' => $map, 'epsg' => GeoJsonSource::guessEpsg($sample, GeoJsonSource::declaredEpsg((string) $this->geoFile->getRealPath()))];
    }

    /** Prova su un campione: quante sagome trovano la loro particella e se cadono in Italia. */
    private function runCheck(?array $sample = null): void
    {
        $this->check = null;
        if (! $this->geoFile || ! $this->draft) {
            return;
        }
        $path = (string) $this->geoFile->getRealPath();
        try {
            $sample ??= GeoJsonSource::sample($path, 50);
            ['map' => $map, 'epsg' => $epsg] = $this->detected($sample);
            if ($epsg !== null) {
                GeoJsonSource::assertEpsg($epsg);
            }
            $byPosition = $this->currentStep() === 'buildings' && ! isset($map['reference']) && ! isset($map['sheet'], $map['parcel']);
            $match = $byPosition ? null : GeoJsonSource::sampleMatch($sample, $map, $this->municipality->cadastral_code, $this->municipality->id, $this->draft->id);
            $this->check = [
                'byPosition' => $byPosition,
                'match' => $match,
                'italy' => $epsg === null ? ['inside' => 0, 'total' => 1] : GeoJsonSource::insideItaly($sample, $epsg),
                'duplicate' => ParcelGeometryImporter::duplicateOf($this->draft->id, $this->currentStep() === 'parcels' ? 'parcel-geometry' : BuildingGeometryImporter::SOURCE, hash_file('sha256', $path))?->created_at?->format('d/m/Y H:i'),
                'epsg' => $epsg,
                'map' => $map,
            ];
        } catch (\Throwable $e) {
            $this->addError('geoFile', $e instanceof \RuntimeException ? $e->getMessage() : 'Non riesco a leggere la posizione delle sagome.');
        }
    }

    /** Esito in parole semplici della lettura del file: ok solo se foglio/particella (o riferimento) sono trovati e il campione si abbina. */
    #[Computed]
    public function geoVerdict(): array
    {
        $c = $this->check;
        if (! $this->analyzed || $c === null) {
            return ['ok' => false, 'message' => ''];
        }
        $parcels = $this->currentStep() === 'parcels';
        if ($c['italy'] && $c['italy']['inside'] === 0) {
            return ['ok' => false, 'message' => 'Le sagome del file non cadono in Italia: il file non sembra corretto. Controlla che sia l’export '.($parcels ? 'delle particelle.' : 'dei fabbricati.')];
        }
        if ($c['byPosition']) {
            return ($this->coverage['located'] ?? 0) > 0
                ? ['ok' => true, 'message' => 'File riconosciuto: i fabbricati si collegheranno alle particelle per posizione']
                : ['ok' => false, 'message' => 'Nel file non trovo foglio e particella (o il riferimento catastale). Carica prima le sagome delle particelle, oppure controlla che sia l’export dei fabbricati.'];
        }
        $m = $c['match'];
        if (! $m || $m['readable'] === 0) {
            return ['ok' => false, 'message' => 'Nel file non trovo foglio e particella (o il riferimento catastale). Controlla che sia l’export '.($parcels ? 'delle particelle.' : 'dei fabbricati.')];
        }
        if ($m['matched'] === 0) {
            return ['ok' => false, 'message' => 'Il file ha foglio e particella, ma nessuna sagoma del campione corrisponde alle particelle di questo Comune. Controlla che sia il file del Comune giusto.'];
        }

        return ['ok' => true, 'message' => "File riconosciuto: foglio e particella trovati · {$m['matched']}/{$m['features']} sagome del campione abbinate"];
    }

    public function importGeometry(): void
    {
        $kind = $this->currentStep();
        abort_unless(in_array($kind, ['parcels', 'buildings'], true) && $this->draft !== null, 422);
        $this->runCheck();
        unset($this->geoVerdict);
        if ($this->geoFile && ! $this->geoVerdict['ok']) {
            $this->addError('geoFile', $this->geoVerdict['message'] ?: 'Il file non è stato riconosciuto.');

            return;
        }
        $this->validate([
            'geoFile' => ['required', 'file', 'extensions:geojson,json', 'max:'.($this->maxMb * 1024)],
        ], [
            'geoFile.required' => 'Carica il file GeoJSON.',
            'geoFile.extensions' => 'Il file deve essere un .geojson o un .json.',
        ]);
        ['map' => $map, 'epsg' => $epsg] = $this->detected(GeoJsonSource::sample((string) $this->geoFile->getRealPath(), 50));
        $epsg = (int) $epsg;

        $stored = $this->geoFile->storeAs('catalog-imports', now()->format('YmdHis').'-'.Str::slug(pathinfo($this->geoFile->getClientOriginalName(), PATHINFO_FILENAME)).'.geojson', 'local');
        try {
            $importer = $kind === 'parcels' ? app(ParcelGeometryImporter::class) : app(BuildingGeometryImporter::class);
            $run = $importer->prepare($this->draft, (string) $stored, $this->geoFile->getClientOriginalName(), $map, $epsg);
        } catch (\RuntimeException $e) {
            Storage::disk('local')->delete((string) $stored);
            $this->addError('geoFile', $e->getMessage());

            return;
        }

        $kind === 'parcels' ? ImportParcelGeometry::dispatch($run->id) : ImportBuildingGeometry::dispatch($run->id);
        $this->resetGeo();
        $this->refresh();
        session()->flash('status', $kind === 'parcels' ? 'Caricamento delle sagome delle particelle avviato.' : 'Caricamento delle sagome dei fabbricati avviato.');
    }

    // ── Passo 4 · pubblica ──────────────────────────────────────────

    public function publish(): void
    {
        if ($this->draft === null) {
            return;
        }
        if ($this->warnings !== [] && ! $this->confirmRisk) {
            $this->addError('publish', 'Leggi gli avvisi e conferma che vuoi pubblicare comunque.');

            return;
        }
        try {
            app(CatalogImporter::class)->publish($this->draft);
            $this->reset('step', 'confirmRisk');
            session()->flash('status', 'Edizione pubblicata: da ora la ricerca usa questa.');
        } catch (\RuntimeException $e) {
            $this->addError('publish', $e->getMessage());
        }
        $this->refresh();
    }

    public function discard(): void
    {
        if ($this->draft === null) {
            return;
        }
        try {
            app(CatalogImporter::class)->discard($this->draft);
        } catch (\RuntimeException $e) {
            $this->addError('publish', $e->getMessage());

            return;
        }
        if (! Municipality::query()->whereKey($this->municipality->id)->exists()) {
            session()->flash('status', 'Bozza scartata: il Comune era stato creato da questa bozza ed è stato rimosso.');
            $this->redirectRoute('admin.catalog.index', navigate: true);

            return;
        }
        $this->reset('step', 'confirmRisk');
        $this->refresh();
        session()->flash('status', 'Bozza scartata.');
    }

    public function restore(int $releaseId): void
    {
        try {
            app(CatalogImporter::class)->restore(CatalogRelease::query()->where('municipality_id', $this->municipality->id)->findOrFail($releaseId));
            session()->flash('status', 'Edizione ripristinata: da ora la ricerca usa questa.');
        } catch (\RuntimeException $e) {
            $this->addError('publish', $e->getMessage());
        }
        $this->refresh();
    }

    private function refresh(): void
    {
        unset($this->draft, $this->runs, $this->ready, $this->busy, $this->stuck, $this->coverage, $this->buildings, $this->warnings, $this->issues, $this->editions);
    }

    public function statusLabel(?string $status): string
    {
        return ['queued' => 'In coda', 'running' => 'In corso', 'done' => 'Completato', 'failed' => 'Fallito', 'discarded' => 'Scartato'][$status] ?? '—';
    }

    public function fmt(int|float|null $n): string
    {
        return number_format((float) $n, 0, ',', '.');
    }
};
?>

<div class="mx-auto max-w-5xl space-y-8 p-6" @if ($this->busy) wire:poll.2s @endif>
    @php($current = $this->currentStep())
    @php($runs = $this->runs)
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <a href="{{ route('admin.catalog.index') }}" wire:navigate class="text-sm text-zinc-500 hover:underline">← Tutti i Comuni</a>
            <flux:heading size="xl" class="mt-1">{{ $municipality->name }} <span class="text-zinc-500">({{ $municipality->cadastral_code }})</span></flux:heading>
            @php($active = $this->editions->firstWhere('release.status', 'active'))
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">
                Edizione pubblicata: {{ $active ? $active['release']->code.' · '.$this->fmt($active['units']).' unità' : 'nessuna' }}
            </p>
        </div>
        @if ($this->provinces->isNotEmpty())
            <flux:select wire:model.live="provinceId" label="Provincia" class="max-w-xs">
                <flux:select.option value="">—</flux:select.option>
                @foreach ($this->provinces as $p)
                    <flux:select.option value="{{ $p->id }}">{{ $p->name }}{{ $p->abbreviation ? ' ('.$p->abbreviation.')' : '' }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif
    </div>

    @if (session('status'))
        <p role="status" class="rounded-lg bg-green-50 p-3 text-green-800">{{ session('status') }}</p>
    @endif
    @error('publish') <p role="alert" class="rounded-lg bg-red-50 p-3 text-red-800">{{ $message }}</p> @enderror
    @if ($this->stuck)
        <p role="alert" class="rounded-lg bg-amber-50 p-3 text-amber-900">
            Un import è in coda da un po’: il processo che esegue le code potrebbe non essere attivo. Avvialo con <code>php artisan queue:work</code> (oppure <code>composer dev</code>).
        </p>
    @endif

    {{-- Nessuna bozza: si parte con «Nuova edizione» --}}
    @if (! $this->draft && $this->editions->isNotEmpty() && ! $newEdition)
        <section class="rounded-xl border p-5">
            <p class="text-sm text-zinc-600 dark:text-zinc-300">Nessuna bozza in corso. Per aggiornare il Comune prepara una nuova edizione con il file SISTER completo e aggiornato.</p>
            <flux:button variant="primary" icon="plus" class="mt-3" wire:click="$set('newEdition', true)">Nuova edizione</flux:button>
        </section>
    @else
        {{-- Passi della bozza --}}
        <nav aria-label="Passi della bozza" class="flex flex-wrap gap-2">
            @foreach (['sister' => '1 · File SISTER', 'parcels' => '2 · Sagome particelle', 'buildings' => '3 · Sagome fabbricati', 'summary' => '4 · Riepilogo e pubblica'] as $key => $label)
                @php($done = $this->stepDone($key))
                <button type="button" wire:click="goTo('{{ $key }}')" @disabled($key !== 'sister' && ! $this->ready)
                    @class(['rounded-full border px-3 py-1 text-sm disabled:opacity-40', 'border-zinc-900 bg-zinc-900 text-white dark:border-white dark:bg-white dark:text-zinc-900' => $current === $key])
                    @if ($current === $key) aria-current="step" @endif>{{ $done ? '✓ ' : '' }}{{ $label }}</button>
            @endforeach
        </nav>

        @if ($current === 'sister')
            <section class="space-y-4 rounded-xl border p-5" aria-labelledby="step-sister">
                <h2 id="step-sister" class="text-lg font-semibold">1 · File SISTER del Comune</h2>
                @if ($runs['sister'])
                    @php($s = $runs['sister'])
                    <p class="text-sm">{{ $s->original_filename }} · <strong>{{ $this->statusLabel($s->status) }}</strong>
                        @if (in_array($s->status, ['running', 'done'], true)) · {{ $this->fmt($s->rows_imported) }} unità importate, {{ $this->fmt($s->rows_rejected) }} righe scartate @endif</p>
                    @if ($s->status === 'failed')
                        <p class="text-sm text-red-700">L’import non è riuscito: scarta la bozza (passo 4) e ricarica il file.</p>
                    @elseif ($s->status === 'done')
                        <flux:button variant="primary" wire:click="goTo('parcels')">Avanti: sagome delle particelle</flux:button>
                    @endif
                @else
                    <p class="text-sm text-zinc-600 dark:text-zinc-300">
                        Carica l’export <strong>completo</strong> dei fabbricati del Comune (testo SISTER separato da tabulazioni, oppure CSV con «;» o «,»).
                        Contiene unità, categorie, consistenze, indirizzi e piani; le posizioni arrivano dalle sagome ai passi successivi.
                    </p>
                    <form wire:submit="importSister" class="space-y-4">
                        <flux:input :value="$municipality->cadastral_code.' · '.$municipality->name" label="Comune" disabled />
                        <flux:input wire:model="note" label="Nota sull’edizione (facoltativa)" />
                        <div>
                            <flux:input type="file" wire:model="file" label="File SISTER (.txt, .csv o .tsv, massimo {{ $this->maxMb }} MB)" accept=".txt,.csv,.tsv,text/plain" />
                            <div wire:loading wire:target="file" class="mt-1 text-sm text-zinc-500">Caricamento del file…</div>
                            @error('file') <p class="mt-1 text-sm text-red-600" role="alert">{{ $message }}</p> @enderror
                            @if ($this->sisterDuplicate)
                                <p class="mt-1 text-sm text-amber-800" role="status">È lo stesso file dell’edizione pubblicata: la nuova edizione sarebbe identica.</p>
                            @endif
                        </div>
                        <div class="flex gap-2">
                            <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="file,importSister">Importa come bozza</flux:button>
                            @if ($newEdition) <flux:button wire:click="$set('newEdition', false)">Annulla</flux:button> @endif
                        </div>
                    </form>
                @endif
            </section>
        @endif

        @if (in_array($current, ['parcels', 'buildings'], true))
            @php($run = $runs[$current])
            @php($isParcels = $current === 'parcels')
            <section class="space-y-4 rounded-xl border p-5" aria-labelledby="step-geo">
                <h2 id="step-geo" class="text-lg font-semibold">{{ $isParcels ? '2 · Sagome delle particelle' : '3 · Sagome dei fabbricati' }}</h2>
                <p class="text-sm text-zinc-600 dark:text-zinc-300">
                    @if ($isParcels)
                        Un GeoJSON con le particelle del Comune (per esempio l’export della mappa da SISTER). Ogni sagoma si abbina alla particella della bozza per sezione, foglio e numero:
                        da lì la particella compare sulla mappa ed è cliccabile.
                    @else
                        Un GeoJSON con i fabbricati del Comune. Ogni sagoma si collega alla sua particella per foglio e numero, oppure, se il file non li ha, per posizione
                        (il fabbricato deve stare almeno per il 20% sulla particella: servono prima le sagome delle particelle). Il file sostituisce i fabbricati già presenti nella bozza.
                        Il passo è facoltativo.
                    @endif
                </p>

                @if ($isParcels && $this->coverage)
                    <p class="text-sm">Particelle con una posizione: <strong>{{ $this->fmt($this->coverage['located']) }} / {{ $this->fmt($this->coverage['parcels']) }}</strong>
                        @if ($this->coverage['located'] > 0 && ! $run) <span class="text-zinc-500">(riprese dall’edizione precedente)</span> @endif</p>
                @endif
                @if (! $isParcels)
                    <p class="text-sm">Fabbricati nella bozza: <strong>{{ $this->fmt($this->buildings) }}</strong></p>
                @endif
                @if ($run)
                    <p class="text-sm">Ultimo file: {{ $run->original_filename }} · <strong>{{ $this->statusLabel($run->status) }}</strong>
                        @if (in_array($run->status, ['running', 'done'], true)) · {{ $this->fmt($run->rows_imported) }} {{ $isParcels ? 'particelle collocate' : 'fabbricati collegati' }}, {{ $this->fmt($run->rows_rejected) }} sagome scartate @endif
                        @if (isset($run->summary['epsg'])) · EPSG:{{ $run->summary['epsg'] }} @endif</p>
                @endif

                <form wire:submit="importGeometry" class="space-y-4">
                    <div>
                        <flux:input type="file" wire:model="geoFile" label="File GeoJSON (.geojson o .json, massimo {{ $this->maxMb }} MB)" accept=".geojson,.json,application/geo+json,application/json" />
                        <div wire:loading wire:target="geoFile" class="mt-1 text-sm text-zinc-500">Caricamento e lettura del file…</div>
                        @error('geoFile') <p class="mt-1 text-sm text-red-600" role="alert">{{ $message }}</p> @enderror
                    </div>

                    @if ($analyzed && $check)
                        @php($verdict = $this->geoVerdict)
                        <div class="space-y-1 text-sm" role="status">
                            @if ($verdict['ok'])
                                <p class="font-medium text-green-700">✓ {{ $verdict['message'] }}</p>
                            @else
                                <p class="font-medium text-red-700" role="alert">{{ $verdict['message'] }}</p>
                            @endif
                            @if ($check['duplicate'])
                                <p class="text-amber-800">Hai già caricato questo stesso file su questa bozza il {{ $check['duplicate'] }}.</p>
                            @endif
                        </div>
                    @endif


                    <div class="flex flex-wrap gap-2">
                        <flux:button type="submit" variant="primary" wire:key="geo-import-{{ $this->geoVerdict['ok'] ? 'on' : 'off' }}" wire:loading.class="opacity-50 pointer-events-none" wire:target="geoFile,importGeometry" :disabled="! $this->geoVerdict['ok']">Importa</flux:button>
                        <flux:button wire:click="goTo('{{ $isParcels ? 'buildings' : 'summary' }}')">{{ $isParcels ? 'Avanti: fabbricati' : 'Avanti: riepilogo' }}</flux:button>
                    </div>
                </form>
            </section>
        @endif

        @if ($current === 'summary' && $this->draft)
            @php($summary = $runs['sister']?->summary ?? [])
            <section class="space-y-4 rounded-xl border p-5" aria-labelledby="step-summary">
                <h2 id="step-summary" class="text-lg font-semibold">4 · Riepilogo della bozza {{ $this->draft->code }}</h2>
                <dl class="grid gap-3 text-sm sm:grid-cols-4">
                    <div><dt class="text-zinc-500">Unità importate</dt><dd class="text-xl font-semibold">{{ $this->fmt($runs['sister']?->rows_imported) }}</dd></div>
                    <div><dt class="text-zinc-500">Cercabili</dt><dd class="text-xl font-semibold">{{ $this->fmt($summary['units']['eligible'] ?? 0) }}</dd></div>
                    <div><dt class="text-zinc-500">Particelle sulla mappa</dt><dd class="text-xl font-semibold">{{ $this->fmt($this->coverage['located'] ?? 0) }} / {{ $this->fmt($this->coverage['parcels'] ?? 0) }}</dd></div>
                    <div><dt class="text-zinc-500">Fabbricati</dt><dd class="text-xl font-semibold">{{ $this->fmt($this->buildings) }}</dd></div>
                </dl>
                @if (! empty($summary['diff']))
                    <p class="text-sm">Rispetto all’edizione {{ $summary['previous_release'] }}:
                        <strong>{{ $this->fmt($summary['diff']['added']) }}</strong> nuove, <strong>{{ $this->fmt($summary['diff']['changed']) }}</strong> variate,
                        <strong>{{ $this->fmt($summary['diff']['unchanged']) }}</strong> invariate, <strong>{{ $this->fmt($summary['diff']['removed']) }}</strong> non più presenti.</p>
                @endif
                @if ($this->issues->isNotEmpty())
                    <details class="text-sm">
                        <summary class="cursor-pointer">Avvisi degli import ({{ $this->issues->count() }}{{ $this->issues->count() >= 12 ? '+' : '' }})</summary>
                        <ul class="mt-2 space-y-1">
                            @foreach ($this->issues as $issue)
                                <li class="{{ $issue->severity === 'error' ? 'text-red-700' : 'text-amber-800' }}">{{ $issue->row_number ? 'Riga '.$issue->row_number.': ' : '' }}{{ $issue->message }}</li>
                            @endforeach
                        </ul>
                    </details>
                @endif
                @if ($this->warnings !== [])
                    <div class="space-y-2 rounded-lg bg-amber-50 p-3 text-sm text-amber-900" role="alert">
                        @foreach ($this->warnings as $w) <p>{{ $w }}</p> @endforeach
                        <flux:checkbox wire:model="confirmRisk" label="Ho letto gli avvisi: pubblica comunque" />
                    </div>
                @endif
                <div class="flex flex-wrap gap-2">
                    <flux:button variant="primary" wire:click="publish" :disabled="! $this->ready"
                        wire:confirm="Pubblicare questa edizione? Da subito la ricerca userà questa al posto di quella attuale.">Pubblica</flux:button>
                    <flux:button wire:click="discard" wire:confirm="Scartare la bozza e tutto quello che ha caricato?">Scarta bozza</flux:button>
                </div>
            </section>
        @elseif ($this->draft && $current !== 'summary')
            <div class="flex justify-end"><flux:button size="sm" variant="ghost" wire:click="discard" wire:confirm="Scartare la bozza e tutto quello che ha caricato?">Scarta bozza</flux:button></div>
        @endif
    @endif

    {{-- Edizioni --}}
    <section class="space-y-3" aria-labelledby="editions-title">
        <h2 id="editions-title" class="text-lg font-semibold">Edizioni</h2>
        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full text-left text-sm">
                <thead class="border-b bg-zinc-50 dark:bg-zinc-900"><tr><th class="p-3">Edizione</th><th class="p-3">Data</th><th class="p-3">Unità</th><th class="p-3">Stato</th><th class="p-3"></th></tr></thead>
                <tbody>
                    @forelse ($this->editions as $edition)
                        @php($r = $edition['release'])
                        <tr class="border-b last:border-0" wire:key="edition-{{ $r->id }}">
                            <td class="p-3">{{ $r->code }}@if ($r->notes) <span class="text-zinc-500">· {{ $r->notes }}</span>@endif</td>
                            <td class="p-3">{{ $r->created_at?->format('d/m/Y H:i') }}</td>
                            <td class="p-3">{{ $this->fmt($edition['units']) }}</td>
                            <td class="p-3">{{ ['active' => 'Pubblicata', 'draft' => 'Bozza', 'archived' => 'Archiviata'][$r->status] ?? $r->status }}</td>
                            <td class="p-3 text-right">
                                @if ($r->status === 'archived')
                                    <flux:button size="sm" wire:click="restore({{ $r->id }})" wire:confirm="Ripristinare l’edizione {{ $r->code }}? Diventa quella usata dalla ricerca e l’attuale viene archiviata.">Ripristina</flux:button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td class="p-3" colspan="5">Nessuna edizione.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
