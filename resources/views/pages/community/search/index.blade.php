<?php

use App\Models\Municipality;
use App\Trova\Categories;
use App\Trova\CatalogSearch;
use App\Trova\SearchException;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public string $code = 'X001';
    public string $segment = 'private'; // 'private' | 'business'
    public string $housing = ''; // '' = abitazioni, 'garage' = box
    public string $businessType = 'all'; // chiavi di Categories::BUSINESS_TYPES
    public array $categories = [];
    public string $min = '';
    public string $max = '';
    public string $address = '';
    public string $section = '';
    public string $sheet = '';
    public string $parcel = '';
    public string $sort = 'surface-desc';
    public string $pageSize = '10';
    public int $page = 1;

    public array $rows = [];
    public int $total = 0;
    public int $units = 0;
    public int $pages = 1;
    public ?string $measure = null;
    public bool $searched = false;

    public string $choice = 'homes'; // homes | garage | business | buildings | land

    public function search(): void
    {
        $this->resetPage();
    }

    public function updatedPage(): void
    {
        $this->run();
    }

    
    
    private function run(): void
    {
        $this->resetErrorBag();
        $this->searched = true;

    
        try {
            $result = app(CatalogSearch::class)->search([
                'code' => $this->code,
                'segment' => $this->segment,
                'housing' => $this->housing,
                'businessType' => $this->businessType,
                'categories' => $this->categories,
                'min' => $this->min,
                'max' => $this->max,
                'address' => $this->address,
                'section' => $this->section,
                'sheet' => $this->sheet,
                'parcel' => $this->parcel,
                'sort' => $this->sort,
                'pageSize' => (int) $this->pageSize,
                'page' => (int) $this->getPage(),
            ]);
        } catch (SearchException $e) {
            $this->addError('search', $e->getMessage());
            $this->rows = [];
            $this->total = 0;
            return;
        }

        $this->rows = $result->rows;
        $this->total = $result->total;
        $this->units = $result->matchedUnits;
        $this->pages = $result->pages();
        $this->measure = $result->measure;

        
        // Pulisci le features della mappa
        unset($this->mapFeatures);
        // Avverti il componente JS che i risultati sono pronti con un evento dispatch
        $this->dispatch('trova-results', features: $this->mapFeatures);
    }

    #[Computed]
    // Comuni con catalogo attivo
    public function municipalities()
    {
        return Municipality::query()->whereHas('catalog')->orderBy('name')->get();
    }

    #[Computed]
    // Categorie selezionabili
    public function selectableCategories(): array
    {
        $groups = $this->segment === 'business' ? Categories::BUSINESS_TYPES[$this->businessType]['groups'] : Categories::scopeGroups('private', $this->housing ?: null);

        return Categories::selectable($groups);
    }

    // Se cambi percorso o tipo, le categorie scelte prima non valgono più
    public function updated(string $property): void
    {
        if (in_array($property, ['segment', 'housing', 'businessType'], true)) {
            $this->categories = [];
        }
    }

    #[Computed]
    public function businessTypes(): array
    {
        return Categories::BUSINESS_TYPES;
    }

    // Misura in cui Da e A sono obbligatori (stessa regola del motore), null se facoltativi
    #[Computed]
    public function rangeMeasure(): ?string
    {
        $groups = $this->segment === 'business' ? Categories::BUSINESS_TYPES[$this->businessType]['groups'] : Categories::scopeGroups('private', $this->housing ?: null);

        return CatalogSearch::requiredMeasure($this->segment, $this->housing ?: null, $groups, $this->categories);
    }

    #[Computed]
    public function unit(): string
    {
        return $this->rangeMeasure ?? 'vani o m²';
    }

    public function value(mixed $value): string
    {
        return $value === null ? '—' : str_replace('.', ',', (string) (float) $value);
    }

    #[Computed]
    public function paginator(): LengthAwarePaginator
    {
        return new LengthAwarePaginator($this->rows, $this->total, (int) $this->pageSize, $this->getPage());
    }

    // Una sagoma per particella; se non c'è, la puntina
    #[Computed]
    public function mapFeatures(): array
    {
        $features = [];
        foreach ($this->rows as $row) {
            $geometry = $row['footprint'] ?? ($row['latitude'] !== null
                ? ['type' => 'Point', 'coordinates' => [$row['longitude'], $row['latitude']]]
                : null);

            if ($geometry !== null) {
                $features[] = [
                    'type' => 'Feature',
                    'id' => $row['parcel_id'], // serve a MapLibre per «accendere» la sagoma
                    'geometry' => $geometry,
                    'properties' => ['parcel' => $row['parcel_id'], 'address' => $row['address']],
                ];
            }
        }

        return $features;
    }


    #[Computed]
    // Scelte di ricerca
    public function choices(): array
    {
        return [
            'homes' => ['title' => 'Abitazioni', 'text' => 'Appartamento o casa indipendente', 'icon' => 'home', 'ready' => true],
            'garage' => ['title' => 'Box auto', 'text' => 'Dimensioni e zona del box', 'icon' => 'truck', 'ready' => true],
            'business' => ['title' => 'Locali e spazi per attività', 'text' => 'Negozi, uffici e altri spazi', 'icon' => 'building-storefront', 'ready' => true],
            'buildings' => ['title' => 'Grandi fabbricati', 'text' => 'Superficie a terra libera', 'icon' => 'building-office-2', 'ready' => false],
            'land' => ['title' => 'Terreni', 'text' => 'Lotti e aree', 'icon' => 'map', 'ready' => false],
        ];
    }

    // Aggiorna la scelta di ricerca
    public function updatedChoice(): void
    {
        abort_unless($this->choices[$this->choice]['ready'] ?? false, 422);

        [$this->segment, $this->housing] = match ($this->choice) {
            'homes' => ['private', ''],
            'garage' => ['private', 'garage'],
            'business' => ['business', ''],
        };

        // Come prima: categorie e ordinamento della scelta precedente non valgono più
        $this->categories = [];
        $this->businessType = 'all';
        $this->sort = $this->segment === 'business' ? 'address' : 'surface-desc';
    }
};

?>

<div class="mx-auto max-w-screen-2xl space-y-6 p-6">
    <form wire:submit="search" class="space-y-6">
        {{-- Comune --}}
        <flux:select wire:model="code" label="In quale Comune vuoi cercare?" class="max-w-md">
            @foreach ($this->municipalities as $m)
                <flux:select.option value="{{ $m->cadastral_code }}">{{ $m->name }}</flux:select.option>
            @endforeach
        </flux:select>

        {{-- Le 5 scelte di Trova --}}
        <fieldset class="space-y-3">
            <legend class="font-semibold">Cosa cerchi?</legend>
            <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-5">
                @foreach ($this->choices as $value => $option)
                    <button type="button"
                        wire:click="$set('choice', '{{ $value }}')"
                        @disabled(! $option['ready'])
                        aria-pressed="{{ $choice === $value ? 'true' : 'false' }}"
                        @class([
                            'flex flex-col items-start gap-2 rounded-xl border p-4 text-left transition',
                            'border-accent bg-reko-yellow-soft' => $choice === $value,
                            'border-zinc-200 bg-white hover:bg-reko-yellow-soft' => $choice !== $value && $option['ready'],
                            'cursor-not-allowed border-zinc-200 bg-zinc-100 opacity-60' => ! $option['ready'],
                        ])>
                        {{-- <flux:icon :name="$option['icon']" class="size-6" /> --}}
                        <span class="font-bold">{{ $option['title'] }}</span>
                        <span class="text-sm text-zinc-500">{{ $option['ready'] ? $option['text'] : 'In arrivo' }}</span>
                    </button>
                @endforeach
            </div>
        </fieldset>

        {{-- Solo per i locali: quale tipo --}}
        @if ($choice === 'business')
            <flux:select wire:model.live="businessType" label="Tipo di locale" class="max-w-md">
                @foreach ($this->businessTypes as $key => $type)
                    <flux:select.option value="{{ $key }}">{{ $type['label'] }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif

        {{-- Categorie: solo se c'è più di una scelta (per i box c'è solo C/6) --}}
        @if (count($this->selectableCategories) > 1)
            <flux:checkbox.group wire:model="categories" label="Categorie">
                <div class="grid grid-cols-3 gap-2 sm:grid-cols-4">
                    @foreach ($this->selectableCategories as $cat)
                        <flux:checkbox class="flex items-center" value="{{ $cat }}"
                            :label="\App\Trova\Categories::label($cat) . ' · ' . $cat" />
                    @endforeach
                </div>
            </flux:checkbox.group>
        @endif

        {{-- Superficie --}}
        <div class="grid gap-4 md:grid-cols-2">
            <flux:input wire:model="min" type="number" min="0.5" step="0.5"
                :required="$this->rangeMeasure !== null" label="Superficie da ({{ $this->unit }})" />
            <flux:input wire:model="max" type="number" min="0.5" step="0.5"
                :required="$this->rangeMeasure !== null" label="Superficie a ({{ $this->unit }})" />
        </div>

        {{-- Indirizzo e riferimenti catastali --}}
        <div class="grid gap-4 md:grid-cols-4">
            <flux:input wire:model="address" label="Indirizzo" placeholder="es. Via Roma" />
            <flux:input wire:model="section" label="Sezione" />
            <flux:input wire:model="sheet" label="Foglio" />
            <flux:input wire:model="parcel" label="Particella" />
        </div>

        {{-- Ordinamento e pagine --}}
        <div class="grid gap-4 md:grid-cols-2">
            <flux:select wire:model="sort" label="Ordina per">
                @if ($segment === 'private')
                    <flux:select.option value="surface-desc">Superficie: dalla più grande</flux:select.option>
                    <flux:select.option value="surface-asc">Superficie: dalla più piccola</flux:select.option>
                @endif
                <flux:select.option value="address">Indirizzo</flux:select.option>
                <flux:select.option value="best">Più unità trovate</flux:select.option>
            </flux:select>

            <flux:select wire:model="pageSize" label="Risultati per pagina">
                <flux:select.option value="10">10</flux:select.option>
                <flux:select.option value="20">20</flux:select.option>
            </flux:select>
        </div>

        <flux:button type="submit" variant="primary" icon="magnifying-glass">Cerca</flux:button>
    </form>

    @error('search')
        <flux:callout variant="danger" icon="x-circle" :heading="$message" />
    @enderror


    {{-- Risultati della ricerca --}}
    @if ($searched && !$errors->has('search'))
        {{-- Lista a sinistra, mappa a destra (una sotto l'altra sugli schermi piccoli) --}}
        <div class="">
        <div class="min-w-0 space-y-4">
            <flux:heading size="lg">{{ $total }} particelle · {{ $units }} unità</flux:heading>

            @if ($total === 0)
                <flux:text>Nessun risultato. Prova ad allargare i filtri.</flux:text>
            @else
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>Indirizzo</flux:table.column>
                        <flux:table.column>Riferimento</flux:table.column>
                        <flux:table.column>Categorie</flux:table.column>
                        <flux:table.column>Consistenza</flux:table.column>
                        <flux:table.column>Unità</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($rows as $row)
                            <flux:table.row :key="$row['parcel_id']" data-parcel="{{ $row['parcel_id'] }}"
                                x-on:mouseenter="$dispatch('trova-highlight', { parcel: {{ $row['parcel_id'] }} })"
                                x-on:mouseleave="$dispatch('trova-highlight', { parcel: null })">
                                {{-- Indirizzo della particella: 📍 centra la mappa, il link apre la scheda --}}
                                <flux:table.cell>
                                    <div class="flex items-center gap-2">
                                        <flux:button size="xs" variant="ghost" icon="map-pin" aria-label="Mostra sulla mappa"
                                            x-on:click="$dispatch('trova-focus', { parcel: {{ $row['parcel_id'] }} })" />
                                        <flux:link :href="route('community.search.parcels.show', $row['parcel_id'])" wire:navigate>
                                            {{ $row['address'] ?? 'Indirizzo non disponibile' }}
                                        </flux:link>
                                    </div>
                                </flux:table.cell>

                                {{-- Sezione (se c'è), foglio, particella --}}
                                <flux:table.cell>
                                    @if ($row['section'] !== '')
                                        Sez. {{ $row['section'] }} ·
                                    @endif
                                    Fg. {{ $row['sheet'] }} · Part. {{ $row['number'] }}
                                </flux:table.cell>

                                {{-- Categorie trovate sulla particella --}}
                                <flux:table.cell>
                                    @foreach ($row['categories'] as $category)
                                        <flux:badge size="sm">{{ $category }}</flux:badge>
                                    @endforeach
                                </flux:table.cell>

                                {{-- Consistenza minima–massima --}}
                                <flux:table.cell>
                                    @if ($row['min_value'] === null)
                                        —
                                    @elseif ($row['min_value'] == $row['max_value'])
                                        {{ $this->value($row['min_value']) }} {{ $measure }}
                                    @else
                                        {{ $this->value($row['min_value']) }}–{{ $this->value($row['max_value']) }}
                                        {{ $measure }}
                                    @endif
                                </flux:table.cell>

                                {{-- Unità trovate: cliccando si apre l'elenco --}}
                                <flux:table.cell @click.stop>
                                    <details>
                                        <summary class="cursor-pointer">{{ $row['records'] }} unità</summary>
                                        <ul class="mt-2 space-y-1 text-sm">
                                            @foreach ($row['units'] as $unit)
                                                <li>
                                                    Sub {{ $unit['sub'] ?? '—' }} · {{ $unit['category'] }}
                                                    @if ($unit['value'] !== null)
                                                        · {{ $this->value($unit['value']) }} {{ $unit['measure'] }}
                                                    @endif
                                                    <span class="text-zinc-500">· {{ $unit['address'] }}</span>
                                                </li>
                                            @endforeach
                                        </ul>
                                    </details>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>

                {{-- Pagine --}}
                <div class="flex items-center justify-center">
            
                    {{ $this->paginator->links() }}
                
                </div>
            @endif
        </div>

        <div wire:ignore
         x-data="trovaMap(@js($this->mapFeatures))"
         x-on:trova-results.window="update($event.detail.features)"
         x-on:trova-highlight.window="highlight($event.detail.parcel)"
         x-on:trova-focus.window="focus($event.detail.parcel)"
         class="h-[600px] overflow-hidden rounded-xl border border-zinc-200 lg:sticky lg:top-6">
            <div x-ref="map" class="h-full w-full"></div>
        </div>
        </div>
    @endif
</div>
