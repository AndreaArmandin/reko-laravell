<?php

use App\Models\ImportRun;
use App\Models\Municipality;
use App\Trova\ZoneImporter;
use App\Trova\ZonePreview;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Zone di ricerca')] class extends Component {
    use WithFileUploads;

    public ?int $municipalityId = null;
    public $file = null;
    public string $nameProperty = 'name';
    public string $idProperty = 'id';
    public string $only = '';
    public bool $replace = false;

    // Esito del controllo (null = ancora da controllare)
    public ?array $checked = null;
    public ?array $preview = null;
    public ?string $done = null;

    // Check di controllo accessi per l'amministratore
    public function boot(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    // Se cambia qualsiasi cosa dopo il controllo, il controllo non vale più
    public function updated(string $property): void
    {
        $this->checked = null;
        $this->preview = null;
        $this->done = null;
        $this->resetErrorBag();
    }

    #[Computed]
    public function municipalities()
    {
        return Municipality::query()->orderBy('name')->get(['id', 'cadastral_code', 'name']);
    }

    #[Computed]
    public function runs()
    {
        return ImportRun::query()->with('municipality:id,name')->where('source_name', 'search-zones')->latest('id')->limit(10)->get();
    }

    // Lancia l'importer: in prova (dry-run) oppure per davvero
    private function run(bool $dryRun): ?ImportRun
    {
        $this->validate([
            'municipalityId' => ['required', 'integer', 'exists:municipalities,id'],
            'file' => ['required', 'file', 'extensions:json,geojson', 'max:20480'],
            'nameProperty' => ['required', 'string', 'max:60'],
            'idProperty' => ['required', 'string', 'max:60'],
            'only' => ['nullable', 'string', 'max:4'],
        ], [
            'municipalityId.required' => 'Scegli il Comune a cui appartengono le zone.',
            'file.required' => 'Carica un file GeoJSON.',
            'file.extensions' => 'Il file deve essere un .geojson o un .json.',
            'file.max' => 'Il file non può superare 20 MB.',
        ]);

        $municipality = Municipality::query()->findOrFail($this->municipalityId);

        try {
            return app(ZoneImporter::class)->import(
                $this->file->getRealPath(),
                $this->only !== '' ? $this->only : null,
                $municipality->cadastral_code,
                $dryRun,
                $this->replace,
                trim($this->nameProperty),
                trim($this->idProperty),
                $this->file->getClientOriginalName(),
            );
        } catch (\RuntimeException $e) {
            $this->addError('file', $e->getMessage());
            return null;
        }
    }

    private function summary(ImportRun $run): array
    {
        return [
            'read' => $run->rows_read,
            'imported' => $run->rows_imported,
            'rejected' => $run->rows_rejected,
            'issues' => $run->issues->map(fn ($i) => $i->only(['severity', 'message', 'row_number']))->all(),
        ];
    }

    // Primo passo: controlla il file senza scrivere nulla
    public function check(): void
    {
        $this->checked = $this->preview = $this->done = null;

        if ($run = $this->run(true)) {
            $this->checked = $this->summary($run);
            $this->preview = ZonePreview::fromFile($this->file->getRealPath(), trim($this->nameProperty));
        }
    }

    // Secondo passo: importa, e conserva il file originale in una cartella privata
    public function import(): void
    {
        if ($this->checked === null || ($this->checked['imported'] ?? 0) === 0) {
            return;
        }

        $run = $this->run(false);
        if (! $run) {
            return;
        }

        $run->update(['private_path' => $this->file->storeAs(
            'zone-imports',
            $run->id.'-'.Str::slug(pathinfo($this->file->getClientOriginalName(), PATHINFO_FILENAME)).'.geojson',
            'local',
        )]);

        $this->done = "Importate {$run->rows_imported} zone".($run->rows_rejected ? ", scartate {$run->rows_rejected}" : '').'.';
        $this->reset('file', 'checked', 'preview');
        unset($this->runs);
    }
};
?>

<div class="mx-auto max-w-4xl space-y-8 p-6">
    <div>
        <flux:heading size="xl">Zone di ricerca</flux:heading>
        <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">
            Carica un file GeoJSON con i quartieri o le frazioni di un Comune. Prima controlli il file e vedi l’anteprima, poi importi:
            fino a quel momento non viene scritto nulla.
        </p>
    </div>

    @if ($done)
        <p role="status" class="rounded-lg bg-green-50 p-3 text-green-800">{{ $done }}</p>
    @endif

    <form wire:submit="check" class="space-y-4">
        <flux:select wire:model.live="municipalityId" label="Comune">
            <flux:select.option value="">Scegli il Comune</flux:select.option>
            @foreach ($this->municipalities as $municipality)
                <flux:select.option value="{{ $municipality->id }}">{{ $municipality->name }} ({{ $municipality->cadastral_code }})</flux:select.option>
            @endforeach
        </flux:select>
        @error('municipalityId') <p class="text-sm text-red-600">{{ $message }}</p> @enderror

        <div>
            <flux:input type="file" wire:model="file" label="File GeoJSON (.geojson o .json, massimo 20 MB)" accept=".geojson,.json,application/geo+json,application/json" />
            <div wire:loading wire:target="file" class="mt-1 text-sm text-zinc-500">Caricamento del file…</div>
            @error('file') <p class="mt-1 text-sm text-red-600" role="alert">{{ $message }}</p> @enderror
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
            <flux:input wire:model.live.debounce.500ms="nameProperty" label="Proprietà con il nome" description="Nel file, es. name o NOME" />
            <flux:input wire:model.live.debounce.500ms="idProperty" label="Proprietà con l’id" description="Se manca lo genero io" />
            <flux:input wire:model.live.debounce.500ms="only" label="Solo codice Comune nel file" description="Opzionale, se il file ne ha più di uno" />
        </div>

        <flux:checkbox wire:model.live="replace" label="Sostituisci le zone di ricerca già presenti di questo Comune" />

        <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="file,check">Controlla il file</flux:button>
    </form>

    @if ($checked)
        <section class="space-y-4 rounded-xl border p-5" aria-labelledby="zone-check-title">
            <h2 id="zone-check-title" class="text-lg font-semibold">Risultato del controllo</h2>
            <p>
                Zone lette: <strong>{{ $checked['read'] }}</strong> ·
                importabili: <strong>{{ $checked['imported'] }}</strong> ·
                scartate: <strong>{{ $checked['rejected'] }}</strong>
            </p>

            @if ($checked['issues'])
                <ul class="space-y-1 text-sm">
                    @foreach ($checked['issues'] as $issue)
                        <li class="{{ $issue['severity'] === 'error' ? 'text-red-700' : 'text-amber-700' }}">Zona {{ $issue['row_number'] }}: {{ $issue['message'] }}</li>
                    @endforeach
                </ul>
            @endif

            @if ($preview)
                <svg viewBox="{{ $preview['viewBox'] }}" class="max-h-96 w-full rounded-lg border bg-zinc-50 dark:bg-zinc-900" role="img" aria-label="Anteprima delle zone">
                    @foreach ($preview['zones'] as $zone)
                        <path d="{{ $zone['d'] }}" fill-rule="evenodd" class="fill-emerald-500/25 stroke-emerald-700" stroke-width="1" vector-effect="non-scaling-stroke"><title>{{ $zone['name'] }}</title></path>
                    @endforeach
                </svg>
                <p class="text-sm text-zinc-600 dark:text-zinc-300">Passa sopra una zona per vederne il nome. Le zone importate restano «indicative»: non sono confini catastali o amministrativi.</p>
            @endif

            @if ($checked['imported'] > 0)
                <flux:button variant="primary" wire:click="import" wire:loading.attr="disabled" wire:target="import">Importa {{ $checked['imported'] }} zone</flux:button>
            @else
                <p class="text-sm text-red-700">Nessuna zona importabile: correggi il file o le proprietà qui sopra.</p>
            @endif
        </section>
    @endif

    <section class="space-y-3" aria-labelledby="zone-runs-title">
        <h2 id="zone-runs-title" class="text-lg font-semibold">Ultimi import</h2>
        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full text-left text-sm">
                <thead class="border-b bg-zinc-50 dark:bg-zinc-900"><tr><th class="p-3">Data</th><th class="p-3">Comune</th><th class="p-3">File</th><th class="p-3">Importate</th><th class="p-3">Scartate</th><th class="p-3">Stato</th></tr></thead>
                <tbody>
                    @forelse ($this->runs as $run)
                        <tr class="border-b last:border-0">
                            <td class="p-3">{{ $run->created_at?->format('d/m/Y H:i') }}</td>
                            <td class="p-3">{{ $run->municipality?->name ?? '—' }}</td>
                            <td class="p-3">{{ $run->original_filename }}</td>
                            <td class="p-3">{{ $run->rows_imported }}</td>
                            <td class="p-3">{{ $run->rows_rejected }}</td>
                            <td class="p-3">{{ $run->status === 'done' ? 'Completato' : ($run->status === 'failed' ? 'Fallito' : $run->status) }}</td>
                        </tr>
                    @empty
                        <tr><td class="p-3" colspan="6">Nessun import ancora.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
