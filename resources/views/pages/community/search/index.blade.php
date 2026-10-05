<?php

use App\Models\Municipality;
use App\Trova\Categories;
use App\Trova\CatalogSearch;
use App\Trova\SearchException;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
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

    public function search(): void
    {
        $this->page = 1;
        $this->run();
    }

    public function goToPage(int $page): void
    {
        $this->page = $page;
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
                'page' => $this->page,
                'pageSize' => (int) $this->pageSize,
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
    }

    #[Computed]
    public function municipalities()
    {
        return Municipality::query()->whereHas('catalog')->orderBy('name')->get();
    }

    #[Computed]
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
};

?>

<div class="mx-auto max-w-6xl space-y-6 p-6">
    <form wire:submit="search" class="space-y-6">
        {{-- Comune, percorso, tipo --}}
        <div class="grid gap-4 md:grid-cols-3">
            <flux:select wire:model="code" label="Comune">
                @foreach ($this->municipalities as $m)
                    <flux:select.option value="{{ $m->cadastral_code }}">{{ $m->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:radio.group wire:model.live="segment" label="Percorso" variant="segmented">
                <flux:radio value="private" label="Privato" />
                <flux:radio value="business" label="Business" />
            </flux:radio.group>

            @if ($segment === 'private')
                <flux:select wire:model.live="housing" label="Cosa cerchi">
                    <flux:select.option value="">Abitazioni</flux:select.option>
                    <flux:select.option value="garage">Box auto</flux:select.option>
                </flux:select>
            @else
                <flux:select wire:model.live="businessType" label="Tipo di immobile">
                    @foreach ($this->businessTypes as $key => $type)
                        <flux:select.option value="{{ $key }}">{{ $type['label'] }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif
        </div>

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
        <div class="space-y-4">
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
                            <flux:table.row :key="$row['parcel_id']">
                                {{-- Indirizzo della particella --}}
                                <flux:table.cell>{{ $row['address'] ?? 'Indirizzo non disponibile' }}</flux:table.cell>

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
                                <flux:table.cell>
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
                <div class="flex items-center justify-between">
                    <flux:button wire:click="goToPage({{ $page - 1 }})" :disabled="$page <= 1" icon="chevron-left">
                        Precedente
                    </flux:button>
                    <flux:text>Pagina {{ $page }} di {{ $pages }}</flux:text>
                    <flux:button wire:click="goToPage({{ $page + 1 }})" :disabled="$page >= $pages"
                        icon-trailing="chevron-right">
                        Successiva
                    </flux:button>
                </div>
            @endif
        </div>
    @endif
</div>
