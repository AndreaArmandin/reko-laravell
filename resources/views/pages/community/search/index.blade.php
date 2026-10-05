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

    #[Computed]
    public function unit(): string
    {
        if ($this->segment === 'private') {
            return $this->housing === 'garage' ? 'm²' : 'vani';
        }

        return 'vani o m²';
    }
};

?>

<div class="mx-auto max-w-6xl space-y-6 p-6">
    <flux:heading size="xl">Trova</flux:heading>

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
            <flux:checkbox.group wire:model="categories" label="Categorie (facoltative)">
                <div class="grid grid-cols-3 gap-2 sm:grid-cols-6">
                    @foreach ($this->selectableCategories as $cat)
                        <flux:checkbox value="{{ $cat }}" label="{{ $cat }}" />
                    @endforeach
                </div>
            </flux:checkbox.group>
        @endif

        {{-- Superficie --}}
        <div class="grid gap-4 md:grid-cols-2">
            <flux:input wire:model="min" type="number" min="0" step="0.5"
                label="Superficie da ({{ $this->unit }})" />
            <flux:input wire:model="max" type="number" min="0" step="0.5"
                label="Superficie a ({{ $this->unit }})" />
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


    @if ($searched)
        <p>{{ $total }} particelle · {{ $units }} unità</p>
    @endif
</div>
