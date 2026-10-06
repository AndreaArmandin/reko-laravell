<?php

use App\Jobs\ImportParcelGeometry;
use App\Jobs\ImportSisterCatalog;
use App\Models\CatalogRelease;
use App\Models\ImportRun;
use App\Trova\CatalogImporter;
use App\Trova\ParcelGeometryImporter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Catalogo')] class extends Component {
    use WithFileUploads;

    // 1 · file SISTER
    public string $code = '';
    public string $name = '';
    public string $note = '';
    public $file = null;

    // 2 · sagome delle particelle
    public ?int $geoReleaseId = null;
    public $geoFile = null;
    public string $sheetProperty = '';
    public string $parcelProperty = '';
    public string $sectionProperty = '';

    // Check di controllo accessi per l'amministratore
    public function boot(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    // Dimensione massima di un file: la più bassa fra i nostri 100 MB e i limiti di PHP
    #[Computed]
    public function maxMb(): int
    {
        $bytes = fn (string $v) => (int) $v * (['k' => 1024, 'm' => 1048576, 'g' => 1073741824][strtolower(substr(trim($v), -1))] ?? 1);
        $php = min($bytes((string) ini_get('upload_max_filesize')) ?: PHP_INT_MAX, $bytes((string) ini_get('post_max_size')) ?: PHP_INT_MAX);

        return max(1, min(100, intdiv($php, 1048576)));
    }

    // Ci sono import in corso: la pagina si aggiorna da sola
    #[Computed]
    public function busy(): bool
    {
        return ImportRun::query()->whereIn('status', ['queued', 'running'])->whereIn('source_name', ['sister-catalog', 'parcel-geometry'])->exists();
    }

    // Import rimasto in coda da troppo: di solito manca il processo che esegue le code
    #[Computed]
    public function stuck(): bool
    {
        return ImportRun::query()->where('status', 'queued')->where('created_at', '<', now()->subSeconds(20))->exists();
    }

    #[Computed]
    public function drafts()
    {
        return CatalogRelease::query()->with('municipality')->where('status', 'draft')->orderByDesc('id')->get()->map(function (CatalogRelease $release) {
            $runs = ImportRun::query()->where('catalog_release_id', $release->id)->orderByDesc('id')->get();
            $sister = $runs->firstWhere('source_name', 'sister-catalog');

            return [
                'release' => $release,
                'sister' => $sister,
                'geometry' => $runs->firstWhere('source_name', 'parcel-geometry'),
                'ready' => $sister?->status === 'done',
                'coverage' => $sister?->status === 'done' ? CatalogImporter::coverage($release->id) : null,
                'issues' => $sister ? $sister->issues()->orderBy('id')->limit(8)->get() : collect(),
            ];
        });
    }

    #[Computed]
    public function editions()
    {
        $releases = CatalogRelease::query()->with('municipality')->orderByDesc('id')->limit(30)->get();
        $units = DB::table('cadastral_unit_versions')->whereIn('catalog_release_id', $releases->pluck('id'))->selectRaw('catalog_release_id as id, count(*) as n')->groupBy('catalog_release_id')->pluck('n', 'id');

        return $releases->map(fn (CatalogRelease $r) => ['release' => $r, 'units' => (int) ($units[$r->id] ?? 0)]);
    }

    // Primo passo: salva il file SISTER e avvia l'import come bozza
    public function importSister(): void
    {
        $this->validate([
            'code' => ['required', 'string', 'regex:/^[A-Za-z]\d{3}$/'],
            'name' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:500'],
            'file' => ['required', 'file', 'extensions:txt,csv,tsv', 'max:'.($this->maxMb * 1024)],
        ], [
            'code.required' => 'Indica il codice catastale del Comune.',
            'code.regex' => 'Il codice catastale ha una lettera e tre cifre, per esempio D205.',
            'file.required' => 'Carica il file SISTER.',
            'file.extensions' => 'Il file deve essere un .txt, .csv o .tsv.',
            'file.max' => "Il file non può superare {$this->maxMb} MB.",
        ]);

        $stored = $this->file->storeAs('catalog-imports', now()->format('YmdHis').'-'.Str::slug(pathinfo($this->file->getClientOriginalName(), PATHINFO_FILENAME)).'.txt', 'local');

        try {
            $run = app(CatalogImporter::class)->prepare($this->code, $this->name, (string) $stored, $this->file->getClientOriginalName(), $this->note);
        } catch (\RuntimeException $e) {
            \Illuminate\Support\Facades\Storage::disk('local')->delete((string) $stored);
            $this->addError('code', $e->getMessage());

            return;
        }

        ImportSisterCatalog::dispatch($run->id);
        $this->reset('code', 'name', 'note', 'file');
        unset($this->drafts, $this->busy, $this->editions);
        session()->flash('status', 'Import avviato: la bozza si riempie qui sotto.');
    }

    // Secondo passo: salva le sagome e avvia l'abbinamento alle particelle della bozza
    public function importGeometry(): void
    {
        $this->validate([
            'geoReleaseId' => ['required', 'integer'],
            'geoFile' => ['required', 'file', 'extensions:geojson,json', 'max:'.($this->maxMb * 1024)],
            'sheetProperty' => ['nullable', 'string', 'max:60', 'required_with:parcelProperty'],
            'parcelProperty' => ['nullable', 'string', 'max:60', 'required_with:sheetProperty'],
            'sectionProperty' => ['nullable', 'string', 'max:60'],
        ], [
            'geoReleaseId.required' => 'Scegli la bozza a cui appartengono le sagome.',
            'geoFile.required' => 'Carica il file GeoJSON.',
            'geoFile.extensions' => 'Il file deve essere un .geojson o un .json.',
            'geoFile.max' => "Il file non può superare {$this->maxMb} MB.",
            'sheetProperty.required_with' => 'Indica sia la proprietà del foglio sia quella della particella, oppure lasciale vuote.',
            'parcelProperty.required_with' => 'Indica sia la proprietà del foglio sia quella della particella, oppure lasciale vuote.',
        ]);

        $release = CatalogRelease::query()->where('status', 'draft')->find($this->geoReleaseId);
        if ($release === null) {
            $this->addError('geoReleaseId', 'Questa bozza non esiste più.');

            return;
        }

        $stored = $this->geoFile->storeAs('catalog-imports', now()->format('YmdHis').'-'.Str::slug(pathinfo($this->geoFile->getClientOriginalName(), PATHINFO_FILENAME)).'.geojson', 'local');

        try {
            $run = app(ParcelGeometryImporter::class)->prepare($release, (string) $stored, $this->geoFile->getClientOriginalName(), [
                'sheet' => trim($this->sheetProperty), 'parcel' => trim($this->parcelProperty), 'section' => trim($this->sectionProperty),
            ]);
        } catch (\RuntimeException $e) {
            \Illuminate\Support\Facades\Storage::disk('local')->delete((string) $stored);
            $this->addError('geoFile', $e->getMessage());

            return;
        }

        ImportParcelGeometry::dispatch($run->id);
        $this->reset('geoFile');
        unset($this->drafts, $this->busy);
        session()->flash('status', 'Caricamento delle sagome avviato.');
    }

    public function publish(int $releaseId): void
    {
        try {
            app(CatalogImporter::class)->publish(CatalogRelease::query()->findOrFail($releaseId));
            session()->flash('status', 'Edizione pubblicata: da ora la ricerca usa questa.');
        } catch (\RuntimeException $e) {
            $this->addError('publish', $e->getMessage());
        }
        unset($this->drafts, $this->editions);
    }

    public function discard(int $releaseId): void
    {
        try {
            app(CatalogImporter::class)->discard(CatalogRelease::query()->findOrFail($releaseId));
            session()->flash('status', 'Bozza scartata.');
        } catch (\RuntimeException $e) {
            $this->addError('publish', $e->getMessage());
        }
        $this->reset('geoReleaseId');
        unset($this->drafts, $this->editions);
    }

    public function statusLabel(string $status): string
    {
        return ['queued' => 'In coda', 'running' => 'In corso', 'done' => 'Completato', 'failed' => 'Fallito', 'discarded' => 'Scartato'][$status] ?? $status;
    }
};
?>

<div class="mx-auto max-w-5xl space-y-10 p-6" @if ($this->busy) wire:poll.2s @endif>
    <div>
        <flux:heading size="xl">Catalogo</flux:heading>
        <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">
            Ogni caricamento crea una <strong>bozza</strong>: gli utenti continuano a cercare nell’edizione pubblicata finché non premi «Pubblica».
            Per un Comune nuovo basta importare il suo file SISTER: il Comune viene creato in quel momento.
        </p>
    </div>

    @if (session('status'))
        <p role="status" class="rounded-lg bg-green-50 p-3 text-green-800">{{ session('status') }}</p>
    @endif
    @error('publish') <p role="alert" class="rounded-lg bg-red-50 p-3 text-red-800">{{ $message }}</p> @enderror

    @if ($this->stuck)
        <p role="alert" class="rounded-lg bg-amber-50 p-3 text-amber-900">
            Un import è in coda da un po’: il processo che esegue le code potrebbe non essere attivo.
            Avvialo con <code>php artisan queue:work</code> (oppure usa <code>composer dev</code>).
        </p>
    @endif

    {{-- 1 · File SISTER --}}
    <section class="space-y-4" aria-labelledby="catalog-sister-title">
        <h2 id="catalog-sister-title" class="text-lg font-semibold">1 · File SISTER del Comune</h2>
        <p class="text-sm text-zinc-600 dark:text-zinc-300">
            Carica l’export <strong>completo</strong> dei fabbricati. Contiene unità, categorie, consistenze, indirizzi e piani, ma non le posizioni: quelle si aggiungono al passo 2.
            Per aggiornare un Comune già presente, ricarica il file completo aggiornato: nella bozza vedrai cosa è cambiato.
        </p>

        <form wire:submit="importSister" class="space-y-4">
            <div class="grid gap-4 sm:grid-cols-[1fr_2fr]">
                <flux:input wire:model="code" label="Codice catastale" description="Es. D205" placeholder="D205" maxlength="4" />
                <flux:input wire:model="name" label="Nome del Comune" description="Serve solo se il Comune è nuovo" placeholder="Cuneo" />
            </div>
            @error('code') <p class="text-sm text-red-600" role="alert">{{ $message }}</p> @enderror

            <flux:input wire:model="note" label="Nota sull’edizione (facoltativa)" />

            <div>
                <flux:input type="file" wire:model="file" label="File SISTER (.txt, .csv o .tsv, massimo {{ $this->maxMb }} MB)" accept=".txt,.csv,.tsv,text/plain" />
                <div wire:loading wire:target="file" class="mt-1 text-sm text-zinc-500">Caricamento del file…</div>
                @error('file') <p class="mt-1 text-sm text-red-600" role="alert">{{ $message }}</p> @enderror
            </div>

            <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="file,importSister">Importa come bozza</flux:button>
        </form>
    </section>

    {{-- Bozze --}}
    <section class="space-y-4" aria-labelledby="catalog-drafts-title">
        <h2 id="catalog-drafts-title" class="text-lg font-semibold">Bozze</h2>

        @forelse ($this->drafts as $draft)
            @php($sister = $draft['sister'])
            @php($geometry = $draft['geometry'])
            @php($summary = $sister?->summary ?? [])
            <article class="space-y-4 rounded-xl border p-5" wire:key="draft-{{ $draft['release']->id }}">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h3 class="font-semibold">{{ $draft['release']->municipality->name }} ({{ $draft['release']->municipality->cadastral_code }})</h3>
                        <p class="text-sm text-zinc-600 dark:text-zinc-300">Edizione {{ $draft['release']->code }} · {{ $sister?->original_filename }}</p>
                    </div>
                    <span class="rounded-full bg-zinc-100 px-3 py-1 text-sm dark:bg-zinc-800">{{ $sister ? $this->statusLabel($sister->status) : '—' }}</span>
                </div>

                @if ($sister && in_array($sister->status, ['queued', 'running'], true))
                    <p class="text-sm">{{ $sister->status === 'queued' ? 'In attesa di partire…' : 'Unità importate finora: '.number_format($sister->rows_imported, 0, ',', '.') }}</p>
                @endif

                @if ($sister && $sister->status === 'done')
                    <dl class="grid gap-3 text-sm sm:grid-cols-4">
                        <div><dt class="text-zinc-500">Unità importate</dt><dd class="text-xl font-semibold">{{ number_format($sister->rows_imported, 0, ',', '.') }}</dd></div>
                        <div><dt class="text-zinc-500">Cercabili</dt><dd class="text-xl font-semibold">{{ number_format((int) ($summary['units']['eligible'] ?? 0), 0, ',', '.') }}</dd></div>
                        <div><dt class="text-zinc-500">Righe scartate</dt><dd class="text-xl font-semibold">{{ number_format($sister->rows_rejected, 0, ',', '.') }}</dd></div>
                        <div><dt class="text-zinc-500">Con una posizione</dt>
                            <dd class="text-xl font-semibold">{{ number_format($draft['coverage']['located'], 0, ',', '.') }} / {{ number_format($draft['coverage']['parcels'], 0, ',', '.') }} <span class="text-sm font-normal">particelle</span></dd></div>
                    </dl>

                    @if (! empty($summary['diff']))
                        <p class="text-sm">
                            Rispetto all’edizione {{ $summary['previous_release'] }}:
                            <strong>{{ number_format($summary['diff']['added'], 0, ',', '.') }}</strong> nuove,
                            <strong>{{ number_format($summary['diff']['changed'], 0, ',', '.') }}</strong> variate,
                            <strong>{{ number_format($summary['diff']['unchanged'], 0, ',', '.') }}</strong> invariate,
                            <strong>{{ number_format($summary['diff']['removed'], 0, ',', '.') }}</strong> non più presenti.
                        </p>
                    @endif

                    @if ($draft['coverage']['located'] < $draft['coverage']['parcels'])
                        <p class="text-sm text-amber-800">
                            {{ number_format($draft['coverage']['parcels'] - $draft['coverage']['located'], 0, ',', '.') }} particelle non hanno ancora una posizione:
                            restano fuori dalla mappa e dai filtri di raggio, zona e quartiere. Carica le sagome al passo 2.
                        </p>
                    @endif
                @endif

                @if ($draft['issues']->isNotEmpty())
                    <details class="text-sm">
                        <summary class="cursor-pointer">Avvisi dell’import ({{ $draft['issues']->count() }}{{ $draft['issues']->count() >= 8 ? '+' : '' }})</summary>
                        <ul class="mt-2 space-y-1">
                            @foreach ($draft['issues'] as $issue)
                                <li class="{{ $issue->severity === 'error' ? 'text-red-700' : 'text-amber-800' }}">{{ $issue->row_number ? 'Riga '.$issue->row_number.': ' : '' }}{{ $issue->message }}</li>
                            @endforeach
                        </ul>
                    </details>
                @endif

                @if ($geometry)
                    <p class="text-sm">
                        Sagome: {{ $geometry->original_filename }} · {{ $this->statusLabel($geometry->status) }}
                        @if ($geometry->status === 'done') · {{ number_format($geometry->rows_imported, 0, ',', '.') }} particelle collocate @endif
                        @if ($geometry->status === 'running') · {{ number_format($geometry->rows_imported, 0, ',', '.') }} finora @endif
                    </p>
                @endif

                <div class="flex flex-wrap gap-2">
                    <flux:button variant="primary" wire:click="publish({{ $draft['release']->id }})" :disabled="! $draft['ready']"
                        wire:confirm="Pubblicare questa edizione? Da subito la ricerca userà questa al posto di quella attuale.">Pubblica</flux:button>
                    <flux:button wire:click="discard({{ $draft['release']->id }})" wire:confirm="Scartare la bozza e tutto quello che ha caricato?">Scarta bozza</flux:button>
                </div>
            </article>
        @empty
            <p class="rounded-xl border p-5 text-sm text-zinc-600 dark:text-zinc-300">Nessuna bozza. Importa un file SISTER qui sopra per crearne una.</p>
        @endforelse
    </section>

    {{-- 2 · Sagome --}}
    <section class="space-y-4" aria-labelledby="catalog-geometry-title">
        <h2 id="catalog-geometry-title" class="text-lg font-semibold">2 · Sagome delle particelle</h2>
        <p class="text-sm text-zinc-600 dark:text-zinc-300">
            Un GeoJSON con le particelle del Comune, per esempio dalla cartografia catastale. Ogni sagoma si abbina alla particella della bozza per
            <strong>sezione, foglio e numero</strong>; da lì si ricava la posizione usata dalla ricerca. Il codice del Comune non basta da solo.
        </p>

        <form wire:submit="importGeometry" class="space-y-4">
            <flux:select wire:model="geoReleaseId" label="Bozza">
                <flux:select.option value="">Scegli la bozza</flux:select.option>
                @foreach ($this->drafts->where('ready', true) as $draft)
                    <flux:select.option value="{{ $draft['release']->id }}">{{ $draft['release']->municipality->name }} · {{ $draft['release']->code }}</flux:select.option>
                @endforeach
            </flux:select>
            @error('geoReleaseId') <p class="text-sm text-red-600" role="alert">{{ $message }}</p> @enderror

            <div>
                <flux:input type="file" wire:model="geoFile" label="File GeoJSON (.geojson o .json, massimo {{ $this->maxMb }} MB)" accept=".geojson,.json,application/geo+json,application/json" />
                <div wire:loading wire:target="geoFile" class="mt-1 text-sm text-zinc-500">Caricamento del file…</div>
                @error('geoFile') <p class="mt-1 text-sm text-red-600" role="alert">{{ $message }}</p> @enderror
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <flux:input wire:model="sheetProperty" label="Proprietà del foglio" description="Nel file, es. FOGLIO" />
                <flux:input wire:model="parcelProperty" label="Proprietà della particella" description="Es. PARTICELLA" />
                <flux:input wire:model="sectionProperty" label="Proprietà della sezione" description="Facoltativa" />
            </div>
            @error('sheetProperty') <p class="text-sm text-red-600" role="alert">{{ $message }}</p> @enderror
            <p class="text-sm text-zinc-600 dark:text-zinc-300">
                Se lasci vuote le proprietà uso il riferimento catastale nazionale (<code>NATIONALCADASTALREFERENCE</code>, per esempio <code>D205_000100.123</code>) dei file dell’Agenzia delle Entrate.
            </p>

            <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="geoFile,importGeometry">Carica le sagome</flux:button>
        </form>
    </section>

    {{-- Edizioni --}}
    <section class="space-y-3" aria-labelledby="catalog-editions-title">
        <h2 id="catalog-editions-title" class="text-lg font-semibold">Edizioni</h2>
        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full text-left text-sm">
                <thead class="border-b bg-zinc-50 dark:bg-zinc-900"><tr><th class="p-3">Comune</th><th class="p-3">Edizione</th><th class="p-3">Data</th><th class="p-3">Unità</th><th class="p-3">Stato</th></tr></thead>
                <tbody>
                    @forelse ($this->editions as $edition)
                        <tr class="border-b last:border-0" wire:key="edition-{{ $edition['release']->id }}">
                            <td class="p-3">{{ $edition['release']->municipality->name }} ({{ $edition['release']->municipality->cadastral_code }})</td>
                            <td class="p-3">{{ $edition['release']->code }}</td>
                            <td class="p-3">{{ $edition['release']->created_at?->format('d/m/Y H:i') }}</td>
                            <td class="p-3">{{ number_format($edition['units'], 0, ',', '.') }}</td>
                            <td class="p-3">{{ ['active' => 'Pubblicata', 'draft' => 'Bozza', 'archived' => 'Archiviata'][$edition['release']->status] ?? $edition['release']->status }}</td>
                        </tr>
                    @empty
                        <tr><td class="p-3" colspan="5">Nessuna edizione.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
