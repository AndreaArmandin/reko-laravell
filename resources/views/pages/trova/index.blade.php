<?php

use App\Models\Municipality;
use App\Models\GeographicZone;
use App\Models\TrovaFavorite;
use App\Models\TrovaSearchHistoryEntry;
use App\Trova\BusinessActivities;
use App\Trova\CatalogSearch;
use App\Trova\Floors;
use App\Trova\HousingV4;
use App\Trova\Presentation;
use App\Trova\SearchException;
use App\Trova\Street;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/*
 * Trova in Livewire: stesso percorso guidato, stessi testi e stessa grafica dell'originale
 * (components/search-bar.tsx, prototype/crm/focused-catalog-journey.tsx, cadastral-search.tsx),
 * sui dati del nostro catalogo PostgreSQL.
 */
new #[Layout('layouts::trova')] class extends Component {
    // Passo del percorso: municipality → zone → type → locations | review → results
    public string $step = 'municipality';

    public string $code = '';

    public string $choice = ''; // homes | garage | business | buildings | land

    // Zona
    public string $address = '';

    public ?string $zoneMethod = null; // address | map | all

    public string $zoneId = '';

    /** @var list<array{0: float, 1: float}> coordinate [longitude, latitude] */
    public array $polygon = [];

    public ?float $lat = null;

    public ?float $lng = null;

    public int $radius = CatalogSearch::RADIUS_DEFAULT;

    // Caratteristiche
    public string $housing = ''; // apartment | independent

    public string $activity = '';

    public string $exactCategory = '';

    public bool $includeRelated = false;

    public string $min = '';

    public string $max = '';

    public string $floorMode = 'any'; // any | range | top

    public string $floorMin = '';

    public string $floorMax = '';

    public string $exactFloor = '';

    public bool $attempted = false;

    // Risultati
    /** @var array<string, mixed>|null criteri congelati al momento della ricerca */
    public ?array $query = null;

    public array $rows = [];

    public int $total = 0;

    public int $units = 0;

    public int $parcels = 0;

    public int $resultPage = 1;

    public int $pages = 1;

    public string $sort = 'surface-desc';

    public ?string $error = null;

    public bool $retry = false;

    /** @var list<string> */
    public array $suggestions = [];

    // ---------------------------------------------------------------- Comune e tipo

    #[Computed]
    public function municipalities()
    {
        return Municipality::query()->whereHas('catalog')->orderBy('name')->get();
    }

    #[Computed]
    public function municipality(): ?Municipality
    {
        return $this->code === '' ? null : Municipality::query()->where('cadastral_code', $this->code)->with('catalog')->first();
    }

    /** Zone delimitate disponibili per il Comune selezionato. */
    #[Computed]
    public function zones()
    {
        $municipalityId = $this->municipality?->id;

        return $municipalityId === null
            ? collect()
            : GeographicZone::query()->where('municipality_id', $municipalityId)->orderBy('name')->get();
    }

    #[Computed]
    public function selectedZone(): ?GeographicZone
    {
        return $this->zoneId === '' ? null : $this->zones->firstWhere('id', (int) $this->zoneId);
    }

    #[Computed]
    public function selectedZoneGeometry(): array
    {
        $zone = $this->selectedZone;
        if ($zone === null) {
            return ['type' => 'FeatureCollection', 'features' => []];
        }

        $record = DB::table('geographic_zones')->where('id', $zone->id)
            ->selectRaw('ST_AsGeoJSON(boundary) AS geojson')->first();
        if (! is_string($record?->geojson)) {
            return ['type' => 'FeatureCollection', 'features' => []];
        }

        return ['type' => 'FeatureCollection', 'features' => [[
            'type' => 'Feature', 'properties' => ['name' => $zone->name],
            'geometry' => json_decode($record->geojson, true, flags: JSON_THROW_ON_ERROR),
        ]]];
    }

    public function zoneLabel(): string
    {
        return match ($this->zoneMethod) {
            'address' => $this->address,
            'map' => 'Punto e raggio',
            'neighborhood' => $this->selectedZone?->name ?? 'Quartiere o frazione',
            'draw' => 'Zona disegnata',
            default => 'Tutto il Comune',
        };
    }

    /** @return array<string, array{title: string, icon: string, ready: bool}> */
    public function choices(): array
    {
        return [
            'homes' => ['title' => 'Abitazioni', 'icon' => 'house', 'ready' => true],
            'garage' => ['title' => 'Box auto', 'icon' => 'car-front', 'ready' => true],
            'business' => ['title' => 'Locali e spazi per attività', 'icon' => 'store', 'ready' => true],
            'buildings' => ['title' => 'Grandi fabbricati', 'icon' => 'building-2', 'ready' => false],
            'land' => ['title' => 'Terreni', 'icon' => 'land-plot', 'ready' => false],
        ];
    }

    public function updatedCode(): void
    {
        $this->choice = '';
        $this->clearZone();
        $this->address = '';
        $this->zoneMethod = null;
        unset($this->municipality, $this->center, $this->floorOptions);
    }

    public function choose(string $choice): void
    {
        if ($this->code === '' || ! isset($this->choices()[$choice])) {
            return;
        }
        if (($choice === 'garage') !== ($this->choice === 'garage')) {
            $this->min = $this->max = $this->exactFloor = '';
        }
        $this->choice = $choice;
        $this->housing = '';
        $this->activity = '';
        $this->exactCategory = '';
        $this->includeRelated = false;
        $this->floorMode = 'any';
        $this->floorMin = $this->floorMax = '';
        unset($this->floorOptions);
    }

    public function isPrivate(): bool
    {
        return in_array($this->choice, ['homes', 'garage'], true);
    }

    // ---------------------------------------------------------------- Zona

    public function setZoneMethod(string $method): void
    {
        if (! in_array($method, ['map', 'all', 'neighborhood', 'draw'], true)) {
            return;
        }
        // Solo i quartieri dipendono dai dati del Comune; il disegno è sempre disponibile, come in Trova
        if ($method === 'neighborhood' && $this->zones->isEmpty()) {
            return;
        }
        $this->clearZone();
        $this->address = '';
        $this->zoneMethod = $method;
        $this->dispatch('trova-map', circle: null, polygon: [], zoneGeometry: ['type' => 'FeatureCollection', 'features' => []], fit: true);
    }

    public function updatedZoneId(): void
    {
        if ($this->zoneId === '') {
            return;
        }
        if ($this->selectedZone === null) {
            $this->zoneId = '';

            return;
        }

        $this->zoneMethod = 'neighborhood';
        $this->address = '';
        $this->lat = $this->lng = null;
        $this->polygon = [];
        $this->dispatch('trova-map', circle: null, polygon: [], zoneGeometry: $this->selectedZoneGeometry, fit: true);
    }

    public function updatedAddress(): void
    {
        $this->clearZone();
        $this->zoneMethod = trim($this->address) !== '' ? 'address' : null;
    }

    public function chooseStreet(string $street): void
    {
        $this->address = $street;
        $this->updatedAddress();
    }

    public function setPoint(float $lat, float $lng): void
    {
        if ($this->zoneMethod !== 'map') {
            return;
        }
        $this->lat = round($lat, 6);
        $this->lng = round($lng, 6);
        $this->dispatch('trova-map', circle: $this->circle(), fit: false);
    }

    /**
     * Perimetro chiuso con «Chiudi zona» (search-zone-picker.tsx): i vertici si disegnano nel browser,
     * qui arriva solo la zona finita. Lista vuota = «Ridisegna», zona da completare.
     *
     * @param  array<mixed>  $points  [longitudine, latitudine]
     */
    public function setPolygon(array $points): void
    {
        if ($this->zoneMethod !== 'draw') {
            return;
        }
        try {
            $this->polygon = $points === [] ? [] : array_map(
                fn (array $p) => [round($p[0], 6), round($p[1], 6)],
                \App\Trova\ZoneBoundary::validate($points),
            );
        } catch (SearchException) {
            $this->polygon = [];
        }
    }

    public function updatedRadius(): void
    {
        $this->radius = (int) (round(max(CatalogSearch::RADIUS_MIN, min(CatalogSearch::RADIUS_MAX, $this->radius)) / CatalogSearch::RADIUS_STEP) * CatalogSearch::RADIUS_STEP);
        $this->dispatch('trova-map', circle: $this->circle(), fit: true);
    }

    private function clearZone(): void
    {
        $this->lat = $this->lng = null;
        $this->zoneId = '';
        $this->polygon = [];
    }

    /** @return array{lat: float, lng: float, radius: int}|null */
    public function circle(): ?array
    {
        return $this->lat !== null && $this->lng !== null ? ['lat' => $this->lat, 'lng' => $this->lng, 'radius' => $this->radius] : null;
    }

    /** Centro dei punti di ricerca del Comune (centro della mappa) */
    #[Computed]
    public function center(): array
    {
        $point = $this->municipality?->catalog === null ? null : DB::selectOne(
            'SELECT ST_Y(ST_Centroid(ST_Collect(location))) lat, ST_X(ST_Centroid(ST_Collect(location))) lng
             FROM parcel_search_points WHERE catalog_release_id = ?',
            [$this->municipality->catalog->catalog_release_id],
        );

        return $point?->lat !== null ? ['lat' => (float) $point->lat, 'lng' => (float) $point->lng] : ['lat' => 41.9, 'lng' => 12.5];
    }

    /** Vie dell'archivio del Comune che contengono il testo scritto (StreetAddressInput) */
    #[Computed]
    public function streets(): array
    {
        return trim($this->address) === '' || mb_strlen(trim($this->address)) < 2 ? [] : $this->streetsLike($this->address);
    }

    /** @return list<string> */
    private function streetsLike(string $text): array
    {
        $releaseId = $this->municipality?->catalog?->catalog_release_id;
        $needle = Street::normalize($text);
        if ($releaseId === null || $needle === '') {
            return [];
        }

        return array_column(DB::select(
            "SELECT street FROM (
                SELECT DISTINCT trim(regexp_replace(upper(address_raw), '\\s+(N\\.|SCALA\\s|INTERNO\\s|PIANO\\s).*$', '')) street
                FROM cadastral_unit_versions WHERE catalog_release_id = ? AND status = 'eligible'
             ) s WHERE ".Street::sql('s.street')." LIKE ? ORDER BY street LIMIT 8",
            [$releaseId, '% '.$needle.'%'],
        ), 'street');
    }

    private function streetExists(string $address): bool
    {
        $releaseId = $this->municipality?->catalog?->catalog_release_id;

        return $releaseId !== null && DB::selectOne(
            "SELECT EXISTS (SELECT 1 FROM cadastral_unit_versions v WHERE v.catalog_release_id = ? AND v.status = 'eligible'
             AND position(' ' || ? || ' ' in ".Street::sql('v.address_raw').') > 0) found',
            [$releaseId, Street::normalize($address)],
        )->found;
    }

    // ---------------------------------------------------------------- Caratteristiche

    /** Misura dell'intervallo: vani, m² o '' (nessuna) */
    #[Computed]
    public function measure(): string
    {
        return match (true) {
            $this->choice === 'homes' => 'vani',
            $this->choice === 'garage' => 'm²',
            default => BusinessActivities::find($this->activity)['measure'] ?? '',
        };
    }

    public function setHousing(string $housing): void
    {
        if (in_array($housing, ['apartment', 'independent'], true)) {
            $this->housing = $housing;
            $this->exactCategory = '';
            if ($housing !== 'apartment') {
                $this->floorMode = 'any';
                $this->floorMin = $this->floorMax = '';
            }
        }
    }

    public function updatedActivity(): void
    {
        $this->includeRelated = false;
        $this->exactCategory = '';
        $this->min = $this->max = '';
        unset($this->measure);
    }

    public function updatedIncludeRelated(): void
    {
        $this->exactCategory = '';
    }

    #[Computed]
    public function exactCategoryOptions(): array
    {
        if ($this->choice === 'garage') {
            return ['C/6'];
        }
        if ($this->choice === 'homes') {
            return array_values(array_filter(\App\Trova\Categories::selectable(['A']), fn ($category) => preg_match('/^A\/[1-9]$/', $category) === 1));
        }
        if ($this->choice === 'business' && $this->activity !== '') {
            return array_values(array_filter(
                BusinessActivities::categories($this->activity, $this->includeRelated),
                fn ($category) => ! preg_match('/^[BE]\//', $category),
            ));
        }

        return [];
    }

    /** Piani documentati nel Comune: abitazioni (catalog-floors.ts) o box su un solo piano */
    #[Computed]
    public function floorOptions(): array
    {
        $releaseId = $this->municipality?->catalog?->catalog_release_id;
        if ($releaseId === null) {
            return [];
        }
        $garage = $this->choice === 'garage';

        return array_map('intval', array_column(DB::select(
            'SELECT DISTINCT level FROM (
                SELECT unnest(f.floor_levels) level, cardinality(f.floor_levels) n FROM trova_unit_facts f
                JOIN cadastral_unit_versions v ON v.cadastral_unit_id = f.cadastral_unit_id AND v.catalog_release_id = f.catalog_release_id
                WHERE f.catalog_release_id = ? AND v.status = \'eligible\' AND '.($garage ? "v.category = 'C/6'" : "v.search_group = 'A'").'
             ) l '.($garage ? 'WHERE n = 1 ' : '').'ORDER BY level',
            [$releaseId],
        ), 'level'));
    }

    // ---------------------------------------------------------------- Percorso

    /** Messaggio che blocca il passo (focused-catalog-journey.tsx) */
    #[Computed]
    public function validationMessage(): string
    {
        $range = in_array($this->measure, ['vani', 'm²'], true) ? Presentation::rangeError($this->min, $this->max, $this->measure) : '';

        return match ($this->step) {
            'municipality' => $this->code === '' ? 'Scegli il Comune.' : ($this->choice === '' || ! $this->choices()[$this->choice]['ready'] ? 'Seleziona un tipo di immobile, poi premi Avanti.' : ''),
            'zone' => match (true) {
                $this->zoneMethod === null => 'Scegli come cercare la zona.',
                $this->zoneMethod === 'address' && trim($this->address) === '' => 'Scrivi una via per continuare.',
                $this->zoneMethod === 'map' && $this->lat === null => 'Tocca la mappa per scegliere un punto.',
                $this->zoneMethod === 'neighborhood' && $this->selectedZone === null => 'Scegli un quartiere o una frazione.',
                $this->zoneMethod === 'draw' && count($this->polygon) < 3 => 'Disegna almeno tre punti e chiudi la zona.',
                default => '',
            },
            'type' => ($this->choice === 'homes' ? $this->housing === '' : $this->activity === '') ? 'Scegli il tipo di immobile o l’attività da cercare.' : $range,
            'review' => $range,
            default => '',
        };
    }

    public function next(): void
    {
        unset($this->validationMessage, $this->measure);
        $this->attempted = true;
        if ($this->validationMessage !== '') {
            return;
        }
        $this->attempted = false;

        if ($this->step === 'municipality') {
            $this->step = 'zone';
        } elseif ($this->step === 'zone') {
            $this->step = $this->choice === 'garage' ? 'review' : 'type';
        } elseif ($this->step === 'type' && $this->choice === 'business') {
            $this->step = 'locations';
        } else {
            $this->search();
        }
    }

    public function back(): void
    {
        $this->attempted = false;
        $this->step = match ($this->step) {
            'zone' => 'municipality',
            'type', 'review' => 'zone',
            'locations' => 'type',
            default => $this->step,
        };
    }

    public function newSearch(): void
    {
        $this->reset();
    }

    public function edit(): void
    {
        $this->step = 'review';
        $this->attempted = false;
    }

    /** Criteri del motore costruiti dalle scelte del percorso (draft di cadastral-search.tsx) */
    private function criteria(): array
    {
        $private = $this->isPrivate();
        $apartment = $this->choice === 'homes' && $this->housing === 'apartment';

        return array_filter([
            'code' => $this->code,
            'segment' => $private ? 'private' : 'business',
            'housing' => $this->choice === 'garage' ? 'garage' : ($private ? $this->housing : null),
            'activity' => $private ? null : $this->activity,
            'categories' => $this->exactCategory !== '' ? [$this->exactCategory] : null,
            'includeRelated' => ! $private && $this->includeRelated ? true : null,
            'min' => $this->measure !== '' ? $this->min : null,
            'max' => $this->measure !== '' ? $this->max : null,
            'address' => $this->zoneMethod === 'address' ? trim($this->address) : null,
            'circle' => $this->zoneMethod === 'map' ? $this->circle() : null,
            'zoneId' => $this->zoneMethod === 'neighborhood' && $this->zoneId !== '' ? (int) $this->zoneId : null,
            'polygon' => $this->zoneMethod === 'draw' && $this->polygon !== [] ? $this->polygon : null,
            'floorMin' => $apartment && $this->floorMode === 'range' && $this->floorMin !== '' ? (int) $this->floorMin : null,
            'floorMax' => $apartment && $this->floorMode === 'range' && $this->floorMax !== '' ? (int) $this->floorMax : null,
            'topFloor' => $apartment && $this->floorMode === 'top' ? true : null,
            'exactFloor' => $this->choice === 'garage' && $this->exactFloor !== '' ? (int) $this->exactFloor : null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    public function search(): void
    {
        $this->query = $this->criteria();
        $this->sort = 'surface-desc';
        $this->resultPage = 1;
        $this->step = 'results';
        $this->run();

        if ($this->error === null) {
            TrovaSearchHistoryEntry::query()->create([
                'user_id' => auth()->id(), 'search_id' => (string) Str::uuid(), 'kind' => 'catalog',
                'criteria' => $this->query, 'total' => $this->total, 'completed_at' => now(), 'expires_at' => now()->addHours(72),
            ]);
        }
    }

    public function updatedSort(): void
    {
        $this->sort = in_array($this->sort, ['surface-desc', 'surface-asc'], true) ? $this->sort : 'surface-desc';
        $this->resultPage = 1;
        $this->run();
    }

    public function goToResults(int $page): void
    {
        $this->resultPage = max(1, min($this->pages, $page));
        $this->run();
    }

    public function retrySearch(): void
    {
        $this->run();
    }

    private function run(): void
    {
        $this->error = null;
        $this->retry = false;
        $this->suggestions = [];
        $this->rows = [];
        $this->total = $this->units = 0;
        unset($this->mapPoints, $this->mapShapes);

        // Trova trova-address-validation.ts: una via assente non è "nessun risultato"
        if (($this->query['address'] ?? '') !== '' && ! $this->streetExists($this->query['address'])) {
            $this->error = 'Questo indirizzo non è presente nell’archivio del Comune selezionato. Scegli una via suggerita oppure modifica l’indirizzo.';
            $words = preg_split('/\s+/', Street::normalize($this->query['address'])) ?: [];
            usort($words, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
            $this->suggestions = $words !== [] ? $this->streetsLike($words[0]) : [];

            return;
        }

        try {
            $result = app(CatalogSearch::class)->search([...$this->query, 'sort' => $this->sort, 'page' => $this->resultPage, 'pageSize' => 10]);
        } catch (SearchException $e) {
            $this->error = Presentation::message($e->getMessage());
            $this->retry = $e->retry;

            return;
        }

        $this->rows = $result->rows;
        $this->total = $result->total;
        $this->units = $result->matchedUnits;
        $this->pages = $result->pages();
        unset($this->mapPoints, $this->mapShapes);
        $this->dispatch('trova-map', points: $this->mapPoints, shapes: $this->mapShapes, circle: $this->query['circle'] ?? null, polygon: $this->query['polygon'] ?? [], zoneGeometry: isset($this->query['zoneId']) ? $this->selectedZoneGeometry : ['type' => 'FeatureCollection', 'features' => []], fit: true);
    }

    // ---------------------------------------------------------------- Presentazione dei risultati

    #[Computed]
    public function mapPoints(): array
    {
        $points = [];
        foreach ($this->rows as $i => $row) {
            if ($row['latitude'] !== null) {
                $n = ($this->resultPage - 1) * 10 + $i + 1;
                $points[] = ['id' => (string) $row['parcel_id'], 'lat' => (float) $row['latitude'], 'lng' => (float) $row['longitude'], 'number' => $n, 'label' => "Apri risultato {$n}"];
            }
        }

        return $points;
    }

    #[Computed]
    public function mapShapes(): array
    {
        $features = [];
        foreach ($this->rows as $row) {
            if ($row['footprint'] !== null) {
                $features[] = ['type' => 'Feature', 'id' => $row['parcel_id'], 'properties' => [], 'geometry' => $row['footprint']];
            }
        }

        return ['type' => 'FeatureCollection', 'features' => $features];
    }

    /** Unità consultabili dell'archivio del Comune ("Copertura e limiti dei dati") */
    #[Computed]
    public function catalogTotal(): int
    {
        $releaseId = $this->municipality?->catalog?->catalog_release_id;

        return $releaseId === null ? 0 : DB::table('cadastral_unit_versions')
            ->where('catalog_release_id', $releaseId)->where('status', 'eligible')
            ->where('category', 'not like', 'B/%')->where('category', 'not like', 'E/%')->count();
    }

    public function appliedMeasure(): string
    {
        return match (true) {
            ($this->query['segment'] ?? '') === 'private' => ($this->query['housing'] ?? '') === 'garage' ? 'm²' : 'vani',
            default => BusinessActivities::find($this->query['activity'] ?? null)['measure'] ?? '',
        };
    }

    public function rowType(array $row): string
    {
        if (($this->query['housing'] ?? '') === 'garage') {
            return 'Box auto';
        }
        if (($this->query['segment'] ?? '') === 'private') {
            return HousingV4::summary(array_filter(array_map(fn ($u) => $u['housing']['esito'] ?? null, $row['units']))) ?: 'Abitazioni';
        }

        return 'Fabbricato · dati per particella';
    }

    public function rowFloors(array $row): string
    {
        $levels = array_unique(array_merge(...array_map(fn ($u) => $u['levels'] ?? [], $row['units'])));
        sort($levels);

        return $levels === [] ? 'Non disponibili' : (count($levels) === 1 ? Floors::label($levels[0]) : Floors::label($levels[0]).' – '.Floors::label(end($levels)));
    }

    public function unitLine(array $unit): string
    {
        $consistency = Presentation::measure($unit['value'], $unit['measure']);

        return $unit['category'].' · Piano '.Floors::text($unit['levels']).($consistency !== 'Non indicata' ? ' · '.$consistency : '');
    }

    public function resultKey(array $row): string
    {
        $kind = ($this->query['segment'] ?? '') === 'private' ? (($this->query['housing'] ?? '') === 'garage' ? 'garage' : 'home') : 'building';

        return 'catalog:'.$kind.':'.$row['parcel_id'];
    }

    // ---------------------------------------------------------------- Immobili salvati e ricerche recenti

    #[Computed]
    public function favorites()
    {
        return TrovaFavorite::query()->where('user_id', auth()->id())->latest()->get();
    }

    public function toggleFavorite(int $index): void
    {
        $row = $this->rows[$index] ?? null;
        if ($row === null) {
            return;
        }
        $key = $this->resultKey($row);
        $existing = TrovaFavorite::query()->where('user_id', auth()->id())->where('result_key', $key)->first();
        if ($existing !== null) {
            $existing->delete();
        } else {
            TrovaFavorite::query()->create(['user_id' => auth()->id(), 'result_key' => $key, 'snapshot' => [
                'label' => Presentation::title((string) $row['address'], (string) $row['section'], (string) $row['sheet'], (string) $row['number']),
                'detail' => implode(' · ', array_filter([
                    $this->municipality?->name, implode(' · ', $row['categories']),
                    Presentation::range($row['min_value'], $row['max_value'], $this->appliedMeasure() ?: null), $this->rowType($row),
                ])),
                'lat' => $row['latitude'], 'lng' => $row['longitude'],
            ]]);
        }
        unset($this->favorites);
    }

    public function removeFavorite(int $id): void
    {
        TrovaFavorite::query()->where('user_id', auth()->id())->whereKey($id)->delete();
        unset($this->favorites);
    }

    #[Computed]
    public function history()
    {
        return TrovaSearchHistoryEntry::query()->where('user_id', auth()->id())->where('expires_at', '>', now())->latest('completed_at')->get();
    }

    public function historyTitle(array $criteria): string
    {
        $what = match (true) {
            ($criteria['housing'] ?? '') === 'garage' => 'Box auto',
            ($criteria['housing'] ?? '') === 'apartment' => 'Appartamento',
            ($criteria['housing'] ?? '') === 'independent' => 'Casa indipendente',
            ($criteria['segment'] ?? '') === 'business' => BusinessActivities::find($criteria['activity'] ?? null)['label'] ?? 'Locali e spazi',
            default => 'Abitazioni',
        };
        $zone = $criteria['address'] ?? (isset($criteria['zoneId'])
            ? (GeographicZone::query()->whereKey($criteria['zoneId'])->value('name') ?? 'Quartiere')
            : (isset($criteria['polygon']) ? 'Zona disegnata' : (isset($criteria['circle']) ? 'Punto e raggio' : 'Tutto il Comune')));

        return $what.' · '.(Municipality::query()->where('cadastral_code', $criteria['code'] ?? '')->value('name') ?? 'Comune non disponibile').' · '.$zone;
    }

    public function repeat(int $id): void
    {
        $entry = TrovaSearchHistoryEntry::query()->where('user_id', auth()->id())->where('expires_at', '>', now())->find($id);
        if ($entry === null) {
            unset($this->history);

            return;
        }
        $c = $entry->criteria;
        $this->reset();
        $this->code = (string) $c['code'];
        $this->choice = ($c['segment'] ?? '') === 'business' ? 'business' : (($c['housing'] ?? '') === 'garage' ? 'garage' : 'homes');
        $this->housing = in_array($c['housing'] ?? '', ['apartment', 'independent'], true) ? $c['housing'] : '';
        $this->activity = (string) ($c['activity'] ?? '');
        $this->includeRelated = (bool) ($c['includeRelated'] ?? false);
        $this->min = (string) ($c['min'] ?? '');
        $this->max = (string) ($c['max'] ?? '');
        $this->address = (string) ($c['address'] ?? '');
        $this->zoneMethod = isset($c['address']) ? 'address' : (isset($c['zoneId']) ? 'neighborhood' : (isset($c['polygon']) ? 'draw' : (isset($c['circle']) ? 'map' : 'all')));
        $this->zoneId = (string) ($c['zoneId'] ?? '');
        $this->polygon = $c['polygon'] ?? [];
        $this->lat = $c['circle']['lat'] ?? null;
        $this->lng = $c['circle']['lng'] ?? null;
        $this->radius = (int) ($c['circle']['radius'] ?? CatalogSearch::RADIUS_DEFAULT);
        $this->floorMode = isset($c['topFloor']) ? 'top' : (isset($c['floorMin']) || isset($c['floorMax']) ? 'range' : 'any');
        $this->floorMin = (string) ($c['floorMin'] ?? '');
        $this->floorMax = (string) ($c['floorMax'] ?? '');
        $this->exactFloor = (string) ($c['exactFloor'] ?? '');
        $this->search();
    }

    public function removeHistory(?int $id = null): void
    {
        TrovaSearchHistoryEntry::query()->where('user_id', auth()->id())->when($id, fn ($q) => $q->whereKey($id))->delete();
        unset($this->history);
    }

    /** Spiegazione del passo corrente (Guida) */
    public function guide(): string
    {
        return match ($this->step) {
            'municipality' => 'Scegli il Comune e il tipo di immobile. La ricerca riguarda solo gli archivi acquisiti del Comune selezionato.',
            'zone' => 'Scrivi una via, scegli un punto e raggio, un quartiere o frazione, disegna un perimetro oppure cerca in tutto il Comune. Quartieri e disegno sono disponibili quando il Comune ha i relativi poligoni.',
            'type', 'review' => 'Indica il tipo di immobile e le dimensioni catastali. Vani e m² sono obbligatori: per un valore preciso usa lo stesso numero in Da e A.',
            'locations' => 'Per ora cerchi una sola sede. La rete di più sedi con distanza minima non è ancora disponibile.',
            default => 'Immobili presenti in archivio, non necessariamente in vendita o in affitto. Tocca un numero sulla mappa per aprire la scheda.',
        };
    }
}; ?>

@php
    $choices = $this->choices();
    $private = $this->isPrivate();
    $garage = $choice === 'garage';
    $measure = $this->measure;
    $invalid = $attempted && $this->validationMessage !== '';
    $rule = \App\Trova\BusinessActivities::find($activity);
    $titles = [
        'zone' => 'Come vuoi scegliere la zona?',
        'type' => $private ? 'Che abitazione cerchi?' : 'Attività e caratteristiche',
        'locations' => 'Una sede o più sedi?',
        'review' => $private ? 'Caratteristiche' : 'Zona e caratteristiche',
    ];
@endphp

<section class="trova-fullscreen" aria-label="REKO Trova" tabindex="-1" x-data="{ guide: false }" x-on:keydown.escape="if (! document.querySelector('dialog[open]')) window.location = '{{ route('home') }}'">
    <header class="trova-header">
        <a class="reko-brand-logo" href="{{ route('home') }}" aria-label="REKO · torna alla home"><img src="/reko-logo.svg" width="158" height="48" alt="REKO"></a>
        <div>
            <button type="button" class="trova-utility" aria-label="Ricerche recenti" x-on:click="$refs.history.showModal()">
                <x-trova.icon name="rotate-ccw-clock" size="19" class="lucide-history" /><span>Ricerche recenti</span>
            </button>
            <button type="button" class="trova-utility" aria-label="Immobili salvati" x-on:click="$refs.favorites.showModal()">
                <x-trova.icon name="star" size="19" /> <span>Immobili salvati</span>
            </button>
            <a class="trova-utility" href="{{ route('home') }}" aria-label="Esci da Trova">
                <x-trova.icon name="x" size="20" /><span>Esci da Trova</span>
            </a>
        </div>
    </header>

    <main class="trova-workspace">
        <div class="trova-slides">

        {{-- 1. Comune e tipo di immobile (search-bar.tsx, passo "municipality") --}}
        @if ($step === 'municipality')
            <section class="wizard-card trova-focused" data-trova-step="municipality" aria-labelledby="wizard-question">
                <div class="flex flex-wrap items-center gap-3 border-b border-border px-5 py-2 sm:px-8"></div>
                <div class="trova-slide-body mx-auto max-w-4xl px-5 py-7 sm:px-8 sm:py-10">
                    <h3 tabindex="-1" id="wizard-question" class="text-2xl font-semibold tracking-tight sm:text-3xl">In quale Comune vuoi cercare?</h3>
                    <div class="mt-7">
                        <div class="flex items-center gap-4 rounded-xl border border-primary/35 bg-primary/[0.07] p-5">
                            <x-trova.icon name="map-pin" class="size-6 text-primary" />
                            <label class="min-w-0 flex-1">
                                <span class="mb-2 block text-sm font-semibold text-muted-foreground">Seleziona il Comune</span>
                                <select wire:model.live="code" class="field-input w-full text-lg" aria-busy="false" aria-describedby="municipality-availability">
                                    <option value="" disabled>Scegli il Comune</option>
                                    @foreach ($this->municipalities as $m)
                                        <option value="{{ $m->cadastral_code }}">{{ $m->name }}</option>
                                    @endforeach
                                </select>
                            </label>
                        </div>
                        <p id="municipality-availability" class="mt-3 text-sm text-muted-foreground">La ricerca riguarda solo il Comune selezionato. La copertura degli archivi è parziale.</p>

                        <fieldset class="trova-start-options mt-6">
                            <legend>Cosa cerchi?</legend>
                            <div role="group" aria-label="Cosa cerchi?">
                                @foreach ($choices as $key => $c)
                                    <button type="button" wire:click="choose('{{ $key }}')" aria-pressed="{{ $choice === $key ? 'true' : 'false' }}" @disabled($code === '')>
                                        <x-trova.icon :name="$c['icon']" /><span>{{ $c['title'] }}</span>
                                    </button>
                                @endforeach
                            </div>
                        </fieldset>
                        <p class="trova-start-hint" role="status">
                            @if ($code === '')
                                Scegli il Comune per vedere i tipi di immobile disponibili.
                            @elseif ($choice === '')
                                Seleziona un tipo di immobile, poi premi Avanti.
                            @elseif (! $choices[$choice]['ready'])
                                {{ $choices[$choice]['title'] }}: ricerca non ancora disponibile per questo Comune.
                            @else
                                Scelta completata. Premi Avanti per impostare la ricerca.
                            @endif
                        </p>
                        <div class="mt-6 flex flex-wrap items-center justify-between gap-4"></div>
                    </div>
                </div>
                <div class="trova-wizard-footer">
                    <button type="button" wire:click="next" data-trova-next @disabled($this->validationMessage !== '')
                        class="group/button inline-flex shrink-0 items-center justify-center rounded-lg border border-transparent bg-clip-padding text-sm font-medium whitespace-nowrap transition-all outline-none select-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 disabled:pointer-events-none disabled:opacity-50 bg-primary text-primary-foreground h-8 gap-1.5 px-2.5 min-w-40">Avanti</button>
                    <x-trova.guide :title="$titles[$step] ?? 'In quale Comune vuoi cercare?'" :text="$this->guide()" />
                </div>
            </section>

        {{-- 2. Risultati (cadastral-search.tsx, guided) --}}
        @elseif ($step === 'results')
            <x-trova.guide title="Risultati della ricerca" :text="$this->guide()" />
            @php
                $measureApplied = $this->appliedMeasure();
                $circleApplied = $query['circle'] ?? null;
                $favoriteKeys = $this->favorites->pluck('result_key')->all();
                $offset = ($resultPage - 1) * 10;
                $segmentLabel = ($query['segment'] ?? '') === 'private' ? (($query['housing'] ?? '') === 'garage' ? 'Box auto' : 'Abitazioni') : 'Locali e spazi';
                $housingLabel = ['apartment' => 'Appartamento', 'independent' => 'Casa indipendente', 'garage' => 'Box auto'][$query['housing'] ?? ''] ?? 'Abitazione';
                $activityApplied = \App\Trova\BusinessActivities::find($query['activity'] ?? null);
                $radiusLabel = $circleApplied ? ($circleApplied['radius'] < 1000 ? $circleApplied['radius'].' m' : \App\Trova\Presentation::number($circleApplied['radius'] / 1000).' km') : null;
            @endphp
            <div class="reko-prototype reko-guided-catalog">
                <div class="reko-finder" data-trova-step="results" x-data="{ view: 'list', units: false }" x-on:trova-open.window="view = 'list'">
                    <div class="reko-journey-top">
                        <h3>Risultati della ricerca</h3>
                        <div class="flex flex-wrap gap-3">
                            <button data-trova-back class="crm-btn secondary" wire:click="edit">Modifica ricerca</button>
                            <button class="crm-link" wire:click="newSearch">Nuova ricerca</button>
                        </div>
                    </div>
                    @if (($query['segment'] ?? '') === 'private')
                        <div class="reko-journey-selection"><strong>La tua richiesta:</strong> {{ $housingLabel }}.<p>Risultati raggruppati per particella. {{ ($query['housing'] ?? '') === 'garage' ? 'Contiamo solo i box auto che rispettano i m² richiesti, senza sommare le superfici.' : 'La configurazione deriva dalle abitazioni e dai piani presenti nell’archivio.' }}</p></div>
                    @endif
                    <div class="trova-access-controls"></div>
                    <nav class="trova-result-view" aria-label="Visualizzazione risultati">
                        <button type="button" :aria-pressed="view === 'list'" x-on:click="view = 'list'">Elenco</button>
                        <button type="button" :aria-pressed="view === 'map'" x-on:click="view = 'map'">Mappa</button>
                    </nav>
                    <div data-view="list" :data-view="view" class="reko-search-workspace is-guided">
                        @if (count($rows) > 0)
                            <section class="reko-map-stage" aria-label="Mappa dei candidati">
                                <div class="reko-map-stage-head"><span>{{ mb_strtoupper($this->municipality?->name ?? '') }} / MAPPA</span><small>{{ $radiusLabel ? 'Raggio '.$radiusLabel : 'Esplora il territorio' }}</small></div>
                                <x-trova.map key="results" :config="['center' => $this->center, 'pick' => false, 'circle' => $circleApplied, 'polygon' => $query['polygon'] ?? [], 'zoneGeometry' => isset($query['zoneId']) ? $this->selectedZoneGeometry : ['type' => 'FeatureCollection', 'features' => []], 'points' => $this->mapPoints, 'shapes' => $this->mapShapes]" />
                                <p class="reko-map-stage-note">La mappa mostra questa pagina di risultati. Tocca un numero per aprire la scheda.</p>
                            </section>
                        @endif

                        <section class="reko-search-results" aria-label="Risultati della ricerca" aria-busy="false" wire:loading.attr="aria-busy">
                            @if ($error)
                                <div class="reko-search-start" role="alert">
                                    <h2>{{ ($query['address'] ?? '') !== '' ? 'Controlla l’indirizzo o i filtri' : 'Ricerca non completata' }}</h2>
                                    <p>{{ $error }}</p>
                                    @if (count($suggestions) > 0)
                                        <p>Forse cercavi:</p>
                                        <div class="crm-actions">
                                            @foreach ($suggestions as $street)
                                                <button type="button" class="crm-btn secondary" wire:click="chooseStreet(@js($street)); $set('step', 'zone')">{{ $street }}</button>
                                            @endforeach
                                        </div>
                                    @endif
                                    <button type="button" class="crm-btn secondary" wire:click="edit">Modifica ricerca</button>
                                    <button class="crm-btn secondary" wire:click="retrySearch">Riprova</button>
                                </div>
                            @else
                                <div class="reko-results-heading"><div>
                                    <p class="proto-eyebrow">{{ $segmentLabel }} · <span x-text="units ? 'Singole unità' : 'Per particella'">Per particella</span></p>
                                    <h2 tabindex="-1" x-init="$el.focus({ preventScroll: true }); $el.scrollIntoView({ block: 'start', behavior: 'instant' })">{{ number_format($total, 0, ',', '.') }} {{ $total === 1 ? 'particella presente' : 'particelle presenti' }}</h2>
                                    <p>Immobili presenti in archivio, non necessariamente in vendita o in affitto.</p>
                                    <p><strong>{{ number_format($units, 0, ',', '.') }} {{ $units === 1 ? 'unità corrispondente' : 'unità corrispondenti' }}</strong> {{ $total === 1 ? 'nella particella selezionata' : 'nelle '.number_format($total, 0, ',', '.').' particelle selezionate' }}. 10 particelle per pagina, tutte le loro unità consultabili.</p>
                                </div></div>

                                <label class="crm-field"><span>Ordina per</span>
                                    <select wire:model.live="sort">
                                        <option value="surface-desc">{{ $measureApplied === 'vani' ? 'Più vani catastali prima' : ($measureApplied === 'm²' ? 'Più m² catastali prima' : 'Consistenza catastale maggiore prima') }}</option>
                                        <option value="surface-asc">{{ $measureApplied === 'vani' ? 'Meno vani catastali prima' : ($measureApplied === 'm²' ? 'Meno m² catastali prima' : 'Consistenza catastale minore prima') }}</option>
                                    </select>
                                </label>
                                <div class="reko-query-tags" aria-label="Criteri applicati">
                                    <span>{{ $this->municipality?->name }}</span>
                                    @if (($query['address'] ?? '') !== '')<span>{{ $query['address'] }}</span>@endif
                                    @if ($activityApplied)<span>{{ $activityApplied['label'] }}{{ ($query['includeRelated'] ?? false) ? ' · ricerca ampliata' : '' }}</span>@endif
                                    @if ($radiusLabel)<span>Raggio {{ $radiusLabel }}</span>@endif
                                    @if (isset($query['zoneId']))<span>{{ \App\Models\GeographicZone::query()->whereKey($query['zoneId'])->value('name') ?? 'Quartiere o frazione' }}</span>@endif
                                    @if (isset($query['polygon']))<span>Zona disegnata</span>@endif
                                    @if (isset($query['floorMin']) || isset($query['floorMax']))<span>{{ isset($query['floorMin']) ? \App\Trova\Floors::label($query['floorMin']) : 'nessun minimo' }} – {{ isset($query['floorMax']) ? \App\Trova\Floors::label($query['floorMax']) : 'nessun massimo' }}</span>@endif
                                    @if (isset($query['min']))<span>Minimo {{ $query['min'] }} {{ (float) $query['min'] === 1.0 && $measureApplied === 'vani' ? 'vano' : $measureApplied }}</span>@endif
                                    @if (isset($query['max']))<span>Massimo {{ $query['max'] }} {{ (float) $query['max'] === 1.0 && $measureApplied === 'vani' ? 'vano' : $measureApplied }}</span>@endif
                                </div>
                                @if (($query['segment'] ?? '') === 'business' && $measureApplied === 'm²')
                                    <p class="reko-filter-note">Il filtro superficie vale solo per le categorie C. Le categorie D restano incluse anche senza superficie disponibile.</p>
                                @endif
                                @if (isset($query['exactFloor']))
                                    <p class="reko-filter-note">Piano esatto: {{ $query['exactFloor'] === 0 ? 'T' : ($query['exactFloor'] < 0 ? 'S'.(-$query['exactFloor']) : $query['exactFloor']) }}. Unità su più piani escluse.</p>
                                @endif
                                @if ($query['topFloor'] ?? false)
                                    <p class="reko-filter-note">Piano abitativo più alto documentato nella particella. Escluse le particelle con piani non determinabili; tutti gli altri filtri sono applicati.</p>
                                @endif
                                @if ($activityApplied)
                                    <p class="reko-filter-note">{{ $activityApplied['label'] }}. Incrociamo categoria, zona e dimensioni disponibili della singola unità. La compatibilità catastale non conferma che l’attività sia autorizzata.</p>
                                @endif
                                @if (($query['housing'] ?? '') !== 'independent')
                                    <nav class="crm-tabs" aria-label="Organizza risultati">
                                        <button type="button" :aria-pressed="! units" x-on:click="units = false">Per particella</button>
                                        <button type="button" :aria-pressed="units" x-on:click="units = true">Singole unità</button>
                                    </nav>
                                    <p class="reko-filter-note">Le due viste mostrano le stesse particelle. Le unità restano raggruppate nella loro particella.</p>
                                @endif

                                <div class="reko-result-grid">
                                    @foreach ($rows as $i => $row)
                                        @php($id = (string) $row['parcel_id'])
                                        <article class="reko-result-card" tabindex="0" wire:key="result-{{ $id }}"
                                            :class="{ 'is-selected': $store.trova.selected === '{{ $id }}' }"
                                            x-on:focus="$store.trova.selected = '{{ $id }}'" x-on:click="$store.trova.selected = '{{ $id }}'"
                                            x-on:mouseenter="$store.trova.hovered = '{{ $id }}'; $store.trova.selected = '{{ $id }}'" x-on:mouseleave="$store.trova.hovered = ''"
                                            x-on:trova-open.window="if ($event.detail.id === '{{ $id }}') $nextTick(() => { $el.focus({ preventScroll: true }); $el.scrollIntoView({ block: 'nearest' }) })">
                                            <div class="reko-result-type">
                                                <x-trova.icon :name="($query['segment'] ?? '') === 'business' ? 'briefcase-business' : (($query['housing'] ?? '') === 'garage' ? 'car-front' : 'house')" size="18" />
                                                <span>{{ $this->rowType($row) }}</span><span>/{{ str_pad((string) ($offset + $i + 1), 2, '0', STR_PAD_LEFT) }}</span>
                                                @php($saved = in_array($this->resultKey($row), $favoriteKeys, true))
                                                <span class="reko-favorite-control"><button type="button" class="reko-favorite" aria-label="{{ $saved ? 'Rimuovi da Immobili salvati' : 'Salva immobile' }}" title="{{ $saved ? 'Rimuovi da Immobili salvati' : 'Salva immobile' }}" aria-pressed="{{ $saved ? 'true' : 'false' }}" wire:click.stop="toggleFavorite({{ $i }})"><x-trova.icon name="star" size="19" /><span>{{ $saved ? 'Salvato' : 'Salva immobile' }}</span></button></span>
                                            </div>
                                            <div class="reko-result-title"><h3>{{ \App\Trova\Presentation::title((string) $row['address'], (string) $row['section'], (string) $row['sheet'], (string) $row['number']) }}</h3></div>
                                            <div class="reko-result-address-row">
                                                <p class="text-sm text-muted-foreground">{{ $this->municipality?->name }} · Foglio {{ $row['sheet'] }} · Particella {{ $row['number'] }}</p>
                                                <div class="reko-result-map-links">
                                                    @if ($row['latitude'] !== null)
                                                        <a class="crm-link" href="https://www.google.com/maps/search/?api=1&query={{ urlencode($row['latitude'].','.$row['longitude']) }}" target="_blank" rel="noopener noreferrer">Apri Google Maps <x-trova.icon name="arrow-up-right" size="16" /></a>
                                                        <a class="crm-link" href="https://www.formaps.it/?mappa.coordinate={{ urlencode($row['latitude'].','.$row['longitude']) }}" target="_blank" rel="noopener noreferrer">Apri forMaps <x-trova.icon name="arrow-up-right" size="16" /></a>
                                                    @endif
                                                </div>
                                            </div>
                                            <dl class="reko-result-summary reko-result-summary-table">
                                                <div><dt>Unità corrispondenti nella particella</dt><dd>{{ $row['records'] }}</dd></div>
                                                <div><dt>Categorie</dt><dd>{{ implode(' · ', $row['categories']) ?: 'Non disponibili' }}</dd></div>
                                                <div><dt>Estremi dei piani rilevati</dt><dd>{{ $this->rowFloors($row) }}</dd></div>
                                                <div><dt>{{ $measureApplied === 'vani' ? 'Vani' : 'Superficie / consistenza' }}</dt><dd>{{ \App\Trova\Presentation::range($row['min_value'], $row['max_value'], $measureApplied ?: null) }}</dd></div>
                                            </dl>
                                            @if ($row['distance_m'] !== null)
                                                <p class="reko-filter-note">Distanza dal punto: {{ $row['distance_m'] >= 1000 ? \App\Trova\Presentation::number(round($row['distance_m'] / 1000, 1)).' km' : round($row['distance_m']).' m' }}</p>
                                            @endif
                                            <details class="reko-matched-parcel-units" :open="units">
                                                <summary><span>Vedi {{ count($row['units']) }} {{ count($row['units']) === 1 ? 'unità corrispondente' : 'unità corrispondenti' }}</span><span class="reko-units-chevron" aria-hidden="true">⌄</span></summary>
                                                <ol start="1" class="reko-matched-units">
                                                    @foreach ($row['units'] as $unit)
                                                        <li>
                                                            <strong>{{ $unit['sub'] !== null ? 'Subalterno '.$unit['sub'] : 'Unità senza subalterno' }}</strong>
                                                            <span>{{ $this->unitLine($unit) }}</span>
                                                            @if (\App\Trova\Presentation::address((string) $unit['address']) !== '')<span>{{ \App\Trova\Presentation::address((string) $unit['address']) }}</span>@endif
                                                            @if ($unit['housing'])
                                                                <div class="reko-housing-v4"><strong>{{ $unit['housing']['esito'] }}</strong>@if ($unit['housing']['conf'] !== '-')<span>Confidenza {{ $unit['housing']['conf'] }}</span>@endif @if ($unit['housing']['livelli_txt'])<span>{{ $unit['housing']['livelli_txt'] }}</span>@endif
                                                                    @if (count($unit['housing']['note']) > 0)<ul>@foreach ($unit['housing']['note'] as $note)<li>{{ $note }}</li>@endforeach</ul>@endif
                                                                </div>
                                                            @endif
                                                            @if ($activityApplied)<p class="reko-filter-note">{{ \App\Trova\BusinessActivities::match($query['activity'], $unit['category']) }}</p>@endif
                                                        </li>
                                                    @endforeach
                                                </ol>
                                            </details>
                                            <div class="reko-card-more"><div>
                                                @if ($row['latitude'] !== null)
                                                    <button class="crm-link reko-show-on-map" x-on:click="$store.trova.selected = '{{ $id }}'; view = 'map'; $dispatch('trova-fly', { id: '{{ $id }}' })"><x-trova.icon name="map-pin" size="16" />Vedi sulla mappa</button>
                                                @else
                                                    <p class="reko-filter-note">Posizione cartografica non disponibile.</p>
                                                @endif
                                            </div></div>
                                        </article>
                                    @endforeach
                                </div>

                                @if ($total === 0)
                                    <div class="crm-empty">Non abbiamo trovato risultati con questi criteri nei dati disponibili.</div>
                                    <div class="reko-empty-actions">
                                        <button class="crm-btn secondary" wire:click="edit">Modifica i filtri</button>
                                        @if ($radiusLabel && $circleApplied['radius'] < \App\Trova\CatalogSearch::RADIUS_MAX)
                                            @php($wider = min(\App\Trova\CatalogSearch::RADIUS_MAX, max(1000, $circleApplied['radius'] * 2)))
                                            <button class="crm-btn secondary" wire:click="$set('radius', {{ $wider }}); search()">Amplia il raggio a {{ $wider < 1000 ? $wider.' m' : \App\Trova\Presentation::number($wider / 1000).' km' }}</button>
                                        @endif
                                        <button class="crm-link" wire:click="edit">Torna al riepilogo</button>
                                    </div>
                                @else
                                    <nav class="reko-pagination" aria-label="Pagine dei risultati">
                                        <button class="crm-btn secondary" @disabled($resultPage <= 1) wire:click="goToResults({{ $resultPage - 1 }})">Precedente</button>
                                        <span>Pagina {{ $resultPage }} di {{ $pages }}</span>
                                        <button class="crm-btn secondary" @disabled($resultPage >= $pages) wire:click="goToResults({{ $resultPage + 1 }})">Successiva</button>
                                    </nav>
                                @endif
                                <details class="reko-results-source">
                                    <summary>Copertura e limiti dei dati</summary>
                                    <p>Archivio del Comune: {{ number_format($this->catalogTotal, 0, ',', '.') }} unità catastali consultabili. Copertura parziale. I dati non costituiscono una visura aggiornata e non indicano la disponibilità alla vendita.</p>
                                    @if (($query['segment'] ?? '') === 'private' && ($query['housing'] ?? '') !== 'garage')
                                        <p>La classificazione v4 considera le abitazioni A, esclusa A/10, per via, civico e scala. A/8 non viene mostrata come appartamento; può comparire solo nel percorso casa indipendente se supera le verifiche. Confrontiamo ogni unità con le altre della particella. I casi «Da verificare» restano visibili. Zona e vani sono applicati alla singola unità.</p>
                                    @endif
                                    @if (isset($query['min']) || isset($query['max']))
                                        <p>Le unità senza consistenza restano in archivio ma non partecipano al filtro dimensionale.</p>
                                    @endif
                                </details>
                            @endif
                        </section>
                    </div>
                </div>
            </div>

        {{-- Passi del percorso guidato (focused-catalog-journey.tsx) --}}
        @else
            <div class="reko-prototype reko-guided-catalog">
                <section data-trova-step="{{ $step }}" class="crm-panel reko-catalog-journey trova-focused" aria-labelledby="catalog-question">
                    <div class="trova-question-heading">
                        <h3 id="catalog-question" tabindex="-1">{{ $titles[$step] }}</h3>
                        <button type="button" data-trova-back class="crm-link" wire:click="back"><x-trova.icon name="arrow-left" size="16" /> Indietro</button>
                    </div>
                    <form class="trova-catalog-slide trova-focus-form" novalidate wire:submit="next">
                        <div class="trova-question-content">
                            @if ($step === 'zone')
                                <div class="trova-zone-address">
                                    <div class="trova-street-field" x-data="{ open: false }" x-on:click.outside="open = false">
                                        <label class="crm-field"><span>Nome della via · civico facoltativo</span>
                                            <input role="combobox" aria-autocomplete="list" aria-controls="trova-streets" :aria-expanded="open" autocomplete="off" maxlength="200" placeholder="Es. via Roma"
                                                wire:model.live.debounce.250ms="address" x-on:focus="open = true" x-on:input="open = true" x-on:keydown.escape.stop="open = false"
                                                @if ($invalid && $zoneMethod === 'address') aria-invalid="true" aria-describedby="trova-filter-error" @endif>
                                        </label>
                                        @if (count($this->streets) > 0)
                                            <ul id="trova-streets" role="listbox" aria-label="Vie suggerite" x-show="open" x-cloak>
                                                @foreach ($this->streets as $street)
                                                    <li role="presentation" wire:key="street-{{ $loop->index }}"><button type="button" role="option" tabindex="-1" x-on:mousedown.prevent x-on:click="open = false; $wire.chooseStreet(@js($street))">{{ $street }}</button></li>
                                                @endforeach
                                            </ul>
                                        @endif
                                        <p class="trova-short-note" role="status">{{ mb_strlen(trim($address)) >= 2 && count($this->streets) === 0 ? 'Nessun suggerimento. Puoi cercare comunque il testo inserito.' : '' }}</p>
                                    </div>
                                </div>
                                <details class="trova-start-choice">
                                    <summary><span>Zona</span><strong>{{ match ($zoneMethod) { 'map' => 'Punto e raggio', 'neighborhood' => $this->selectedZone?->name ?? 'Quartiere o frazione', 'draw' => 'Disegna zona', 'all' => 'Tutto il Comune', default => 'Scegli un metodo' } }}</strong></summary>
                                    <div role="group" aria-label="Metodo di ricerca della zona">
                                        <button type="button" aria-pressed="{{ $zoneMethod === 'map' ? 'true' : 'false' }}" wire:click="setZoneMethod('map')" x-on:click="$el.closest('details').removeAttribute('open')"><x-trova.icon name="map-pin" /><span>Punto e raggio</span></button>
                                        <button type="button" aria-pressed="{{ $zoneMethod === 'neighborhood' ? 'true' : 'false' }}" wire:click="setZoneMethod('neighborhood')" @disabled($this->zones->isEmpty()) x-on:click="$el.closest('details').removeAttribute('open')"><x-trova.icon name="map" /><span>Quartieri e frazioni</span></button>
                                        <button type="button" aria-pressed="{{ $zoneMethod === 'draw' ? 'true' : 'false' }}" wire:click="setZoneMethod('draw')" x-on:click="$el.closest('details').removeAttribute('open')"><x-trova.icon name="pencil" /><span>Disegna zona</span></button>
                                        <button type="button" aria-pressed="{{ $zoneMethod === 'all' ? 'true' : 'false' }}" wire:click="setZoneMethod('all')" x-on:click="$el.closest('details').removeAttribute('open')"><x-trova.icon name="building-2" /><span>Tutto il Comune</span></button>
                                    </div>
                                </details>
                                @if ($zoneMethod === 'neighborhood')
                                    <label class="crm-field mt-4"><span>Quartiere o frazione</span>
                                        <select wire:model.live="zoneId" @if ($invalid) aria-invalid="true" aria-describedby="trova-filter-error" @endif>
                                            <option value="">Scegli un quartiere o una frazione</option>
                                            @foreach ($this->zones as $zone)
                                                <option value="{{ $zone->id }}">{{ $zone->name }}{{ $zone->indicative ? ' · indicativa' : '' }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    @if ($this->selectedZone?->notice)
                                        <p class="trova-short-note">{{ $this->selectedZone->notice }}</p>
                                    @endif
                                @endif
                                <div class="trova-zone-overview" aria-label="Mappa di {{ $this->municipality?->name }}">
                                    <x-trova.map :key="'zone-'.$zoneMethod"
                                        :config="['center' => $this->center, 'pick' => $zoneMethod === 'map', 'draw' => $zoneMethod === 'draw', 'circle' => $this->circle(), 'polygon' => $polygon, 'zoneGeometry' => $this->selectedZoneGeometry, 'points' => [], 'shapes' => null]" />
                                </div>
                                @if ($zoneMethod === 'map')
                                    @php($radiusText = $radius < 1000 ? $radius.' m' : \App\Trova\Presentation::number($radius / 1000).' km')
                                    <div class="reko-radius-control" dir="ltr">
                                        <div class="reko-radius-heading"><label for="trova-radius">Raggio di ricerca</label><output for="trova-radius">{{ $radiusText }}</output></div>
                                        <input id="trova-radius" type="range" min="200" max="5000" step="50" wire:model.live.debounce.150ms="radius" aria-valuetext="{{ $radiusText }}" aria-describedby="trova-radius-help">
                                        <div class="reko-radius-limits" aria-hidden="true"><span>200 m</span><span>5 km</span></div>
                                        <p id="trova-radius-help">Sposta il cursore: a sinistra riduci, a destra ampli la ricerca.</p>
                                    </div>
                                @endif
                                @if ($invalid)
                                    <p class="trova-validation-error" id="trova-filter-error" role="alert">{{ $this->validationMessage }}</p>
                                @endif
                                @if ($this->zones->isEmpty())
                                    <p class="trova-short-note">Quartieri e zone disegnate non sono ancora disponibili per questo Comune: usa un punto, una via o tutto il Comune.</p>
                                @else
                                    <p class="trova-short-note">Le zone sono indicative. Il perimetro disegnato filtra gli immobili usando i punti geografici dell’archivio.</p>
                                @endif

@elseif ($step === 'type' && $choice === 'homes')
                                <div class="trova-choice-grid" role="group" aria-label="Che abitazione cerchi?">
                                    @foreach (['apartment' => ['Appartamento', 'building-2'], 'independent' => ['Casa indipendente', 'house']] as $value => [$label, $icon])
                                        <button type="button" class="reko-home-choice" aria-pressed="{{ $housing === $value ? 'true' : 'false' }}" wire:click="setHousing('{{ $value }}')"><x-trova.icon :name="$icon" /><strong>{{ $label }}</strong></button>
                                    @endforeach
                                </div>
                                <section class="trova-visible-filters" aria-label="Caratteristiche"><h4>Vani catastali</h4><x-trova.range unit="vani" /></section>
                                @if ($housing === 'apartment')
                                    <section class="trova-visible-filters" aria-label="Preferenza piano">
                                        <h4>Preferenza piano</h4>
                                        <label class="crm-field"><span>Piano</span>
                                            <select wire:model.live="floorMode">
                                                <option value="any">Qualsiasi piano</option>
                                                <option value="range">Intervallo di piani</option>
                                                <option value="top">Piano abitativo più alto documentato nella particella</option>
                                            </select>
                                        </label>
                                        @if ($floorMode === 'range')
                                            <div class="trova-range">
                                                <label class="crm-field"><span>Dal piano</span><select wire:model.live="floorMin"><option value="">Nessun minimo</option>@foreach ($this->floorOptions as $f)<option value="{{ $f }}">{{ \App\Trova\Floors::label($f) }}</option>@endforeach</select></label>
                                                <label class="crm-field"><span>Al piano</span><select wire:model.live="floorMax"><option value="">Nessun massimo</option>@foreach ($this->floorOptions as $f)@if ($floorMin === '' || $f >= (int) $floorMin)<option value="{{ $f }}">{{ \App\Trova\Floors::label($f) }}</option>@endif @endforeach</select></label>
                                            </div>
                                        @endif
                                    </section>
                                @elseif ($housing === 'independent')
                                    <section class="trova-visible-filters" aria-label="Superficie esterna"><h4>Superficie esterna</h4><p>Misure esterne non disponibili per questo Comune.</p><p class="trova-short-note">Spazio scoperto della particella, esclusi gli edifici.</p></section>
                                @endif
                                <label class="crm-field mt-4"><span>Categoria catastale precisa</span><select wire:model.live="exactCategory"><option value="">Tutte le categorie abitative</option>@foreach ($this->exactCategoryOptions as $category)<option value="{{ $category }}">{{ $category }} · {{ \App\Trova\Categories::label($category) }}</option>@endforeach</select></label>
                                <p class="trova-short-note" data-field-help>La categoria precisa restringe i risultati catastali. Destinazione e uso effettivo vanno verificati.</p>

@elseif ($step === 'type')
                                <label class="crm-field"><span>Attività</span>
                                    <select required wire:model.live="activity">
                                        <option value="" disabled>Scegli la tua attività</option>
                                        @foreach (\App\Trova\BusinessActivities::GROUPS as $group => $ids)
                                            <optgroup label="{{ $group }}">
                                                @foreach ($ids as $id)<option value="{{ $id }}">{{ \App\Trova\BusinessActivities::ALL[$id]['label'] }}</option>@endforeach
                                            </optgroup>
                                        @endforeach
                                    </select>
                                </label>
                                @if ($rule)
                                    <p class="trova-short-note" role="status">{{ $rule['measure'] === 'vani' ? 'Per questa attività si usano i vani catastali, non i m²: non sono convertibili.' : ($rule['measure'] === 'm²' ? 'Il filtro superficie vale solo per le categorie C. Le categorie D restano incluse anche senza superficie disponibile.' : 'La fonte non riporta dimensioni confrontabili per questa attività.') }}</p>
                                    @if ($activity === 'parking')
                                        <p class="trova-short-note">Qui cerchi spazi per un’attività di parcheggio. La categoria C/6 comprende anche box: l’uso effettivo va verificato.</p>
                                    @endif
                                    @if (count($rule['related']) > 0)
                                        <label class="crm-check"><input type="checkbox" wire:model.live="includeRelated">Includi spazi alternativi da verificare</label>
                                    @endif
                                    <span x-data>
                                        <button type="button" class="trova-help-button" aria-label="Come selezioniamo gli immobili" title="Come selezioniamo gli immobili" aria-haspopup="dialog" x-on:click="$refs.activityHelp.showModal()"><x-trova.icon name="circle-question-mark" size="21" /></button>
                                        <dialog x-ref="activityHelp" class="trova-help-dialog" aria-labelledby="activity-help-title" x-on:click="if ($event.target === $el) $el.close()">
                                            <header><h2 id="activity-help-title">Come selezioniamo gli immobili</h2><button type="button" aria-label="Chiudi spiegazione" x-on:click="$refs.activityHelp.close()"><x-trova.icon name="x" size="22" /></button></header>
                                            <div class="trova-help-content">
                                                <p>{{ $rule['description'] }}</p>
                                                <p>Tipi di spazio: {{ implode(' · ', \App\Trova\BusinessActivities::categories($activity, $includeRelated)) }}.</p>
                                                <p>Categoria catastale e autorizzazione all’attività sono informazioni diverse. Destinazione urbanistica e requisiti vanno verificati. Non sommiamo superfici di unità diverse.</p>
                                            </div>
                                            <footer><button type="button" class="trova-dialog-done" x-on:click="$refs.activityHelp.close()">Ho capito</button></footer>
                                        </dialog>
                                    </span>
                                @endif
                                @if ($measure !== '')
                                    <section class="trova-visible-filters" aria-label="Caratteristiche"><h4>{{ $measure === 'vani' ? 'Vani catastali' : 'Dimensione · '.$measure }}</h4><x-trova.range :unit="$measure" /></section>
                                @endif
                                @if ($measure === 'm²')
                                    <p class="trova-short-note">La superficie filtra le categorie C; le categorie D restano incluse anche senza superficie disponibile.</p>
                                @endif
                                @if ($activity !== '')
                                    <label class="crm-field mt-4"><span>Categoria catastale precisa</span><select wire:model.live="exactCategory"><option value="">Tutte quelle previste per questa attività</option>@foreach ($this->exactCategoryOptions as $category)<option value="{{ $category }}">{{ $category }} · {{ \App\Trova\Categories::label($category) }}</option>@endforeach</select></label>
                                    <p class="trova-short-note" data-field-help>La categoria precisa filtra l’archivio. Non dimostra che l’immobile sia autorizzato per l’attività.</p>
                                @endif

@elseif ($step === 'locations')
                                <p class="trova-short-note">Cerchi più sedi?</p>
                                <div class="trova-choice-grid">
                                    <button type="button" class="reko-home-choice" aria-label="Una sede" aria-pressed="true"><x-trova.icon name="building-2" /><strong>Una sede</strong></button>
                                    <button type="button" class="reko-home-choice" aria-label="Più sedi" aria-pressed="false" disabled title="Non ancora disponibile"><x-trova.icon name="building-2" /><strong>Più sedi</strong></button>
                                </div>
                                <p class="trova-short-note">La ricerca di più sedi a distanza minima non è ancora disponibile.</p>

@elseif ($step === 'review')
                                <div class="trova-criteria-summary">
                                    <div class="trova-criteria-main">
                                        @if (! $private)
                                            <div><h4>Tipo di spazio</h4><p>{{ $rule['label'] ?? '' }}</p><button type="button" class="crm-link" wire:click="$set('step', 'type')">Modifica tipo di spazio</button></div>
                                        @endif
                                        <div><h4>In quale zona?</h4><p>{{ $this->municipality?->name }} · {{ $this->zoneLabel() }}</p><button type="button" class="crm-btn secondary" wire:click="$set('step', 'zone')"><x-trova.icon name="map" size="18" /> Scegli zona / Mappa</button></div>
                                    </div>
                                    @if ($activity === 'office')
                                        <p>Il catasto misura gli uffici in vani, non in m².</p>
                                    @endif
                                    @if (! $private && $measure === 'm²')
                                        <p class="trova-short-note">Il filtro superficie vale solo per le categorie C. Le categorie D restano incluse anche senza superficie disponibile.</p>
                                    @endif
                                    @if ($measure !== '')
                                        <div><h4>{{ $measure === 'vani' ? 'Vani catastali · obbligatori' : 'Superficie · m² obbligatori' }}</h4><x-trova.range :unit="$measure" /></div>
                                    @endif
                                    @if ($garage)
                                        <label class="crm-field"><span>Categoria catastale</span><select wire:model.live="exactCategory"><option value="">Tutte quelle ammesse</option>@foreach ($this->exactCategoryOptions as $category)<option value="{{ $category }}">{{ $category }} · {{ \App\Trova\Categories::label($category) }}</option>@endforeach</select></label>
                                        <p class="trova-short-note" data-field-help>Per il percorso Box auto la ricerca è limitata alla categoria C/6.</p>
                                        <label class="crm-field"><span>Piano del box</span>
                                            <select wire:model="exactFloor"><option value="">Qualsiasi piano</option>@foreach ($this->floorOptions as $f)<option value="{{ $f }}">{{ \App\Trova\Floors::option($f) }}</option>@endforeach</select>
                                        </label>
                                    @endif
                                    @if ($choice === 'homes' && $housing !== '')
                                        @php($active = (int) ($housing === 'apartment' && ($floorMode === 'top' || ($floorMode === 'range' && ($floorMin !== '' || $floorMax !== '')))))
                                        <details class="trova-more-filters" @if ($active) open @endif>
                                            <summary>Altri filtri @if ($active)<span class="trova-active-filter-count"> · {{ $active }} attivo</span>@endif</summary>
                                            @if ($housing === 'apartment')
                                                <label class="crm-field"><span>Preferenza sul piano</span>
                                                    <select wire:model.live="floorMode">
                                                        <option value="any">Qualsiasi piano</option>
                                                        <option value="range">Intervallo di piani</option>
                                                        <option value="top">Piano abitativo più alto documentato nella particella</option>
                                                    </select>
                                                </label>
                                                @if ($floorMode === 'range')
                                                    <div class="trova-range">
                                                        <label class="crm-field"><span>Dal piano</span><select wire:model.live="floorMin"><option value="">Nessun limite</option>@foreach ($this->floorOptions as $f)<option value="{{ $f }}">{{ \App\Trova\Floors::label($f) }}</option>@endforeach</select></label>
                                                        <label class="crm-field"><span>Al piano</span><select wire:model.live="floorMax"><option value="">Nessun limite</option>@foreach ($this->floorOptions as $f)@if ($floorMin === '' || $f >= (int) $floorMin)<option value="{{ $f }}">{{ \App\Trova\Floors::label($f) }}</option>@endif @endforeach</select></label>
                                                    </div>
                                                @elseif ($floorMode === 'top')
                                                    <p>È il piano abitativo più alto documentato per ciascuna particella, non necessariamente l’ultimo piano dell’intero edificio.</p>
                                                @endif
                                            @else
                                                <h4>Superficie esterna · esclusi gli edifici</h4>
                                                <p>Misure esterne non disponibili per questo Comune.</p>
                                            @endif
                                        </details>
                                    @endif
                                </div>
@endif
                        </div>
                        @if ($invalid && ! in_array($step, ['zone'], true))
                            <p class="trova-validation-error" id="trova-filter-error" role="alert">{{ $this->validationMessage }}</p>
                        @endif
                        <div class="trova-step-actions">
                            <button data-trova-next class="crm-btn" type="submit">
                                @if (($private && in_array($step, ['type', 'review'], true)) || $step === 'locations')
                                    <x-trova.icon name="search" size="18" /> Mostra risultati
                                @else
                                    Avanti
                                @endif
                            </button>
                            <x-trova.guide :title="$titles[$step] ?? 'In quale Comune vuoi cercare?'" :text="$this->guide()" />
                        </div>
                    </form>
                </section>
            </div>
        @endif
        </div>
    </main>

    <dialog x-ref="history" class="trova-help-dialog" aria-labelledby="trova-history-title" x-on:click="if ($event.target === $el) $el.close()">
        <header><h2 id="trova-history-title">Ricerche recenti</h2><button type="button" aria-label="Chiudi spiegazione" x-on:click="$refs.history.close()"><x-trova.icon name="x" size="22" /></button></header>
        <div class="trova-help-content">
            <button class="crm-btn trova-new-search" type="button" wire:click="newSearch" x-on:click="$refs.history.close()">Nuova ricerca</button>
            <p>Le ricerche completate negli ultimi 3 giorni. La scadenza non cambia quando le apri.</p>
            @if ($this->history->isEmpty())
                <p>Nessuna ricerca recente.</p>
            @endif
            <ul class="trova-history-list">
                @foreach ($this->history as $entry)
                    <li wire:key="history-{{ $entry->id }}">
                        <h3>{{ $this->historyTitle($entry->criteria) }}</h3>
                        <p>{{ number_format($entry->total, 0, ',', '.') }} {{ $entry->total === 1 ? 'particella' : 'particelle' }}</p>
                        <p class="trova-help-note">Completata: {{ $entry->completed_at->locale('it')->isoFormat('D MMM YYYY, HH:mm') }}<br>Scade: {{ $entry->expires_at->locale('it')->isoFormat('D MMM YYYY, HH:mm') }}</p>
                        <div class="trova-history-actions">
                            <button class="crm-btn secondary" wire:click="repeat({{ $entry->id }})" x-on:click="$refs.history.close()">Ripeti ricerca</button>
                            <button class="crm-btn secondary" type="button" wire:click="removeHistory({{ $entry->id }})" wire:confirm="Eliminare questa ricerca recente?">Cancella ricerca</button>
                        </div>
                    </li>
                @endforeach
            </ul>
            @if ($this->history->isNotEmpty())
                <button class="crm-link" type="button" wire:click="removeHistory" wire:confirm="Eliminare tutte le ricerche recenti?">Elimina tutte le ricerche</button>
            @endif
            <p class="trova-help-note">«Ripeti ricerca» cerca sui dati aggiornati con i filtri indicati. Gli immobili salvati non cambiano.</p>
        </div>
        <footer><button type="button" class="trova-dialog-done" x-on:click="$refs.history.close()">Chiudi</button></footer>
    </dialog>

    <dialog x-ref="favorites" class="reko-favorites-dialog" aria-labelledby="favorites-title" x-on:click="if ($event.target === $el) $el.close()">
        <header><h2 id="favorites-title">Immobili salvati</h2><button type="button" x-on:click="$refs.favorites.close()" aria-label="Chiudi preferiti"><x-trova.icon name="x" /></button></header>
        @if ($this->favorites->isEmpty())
            <p>Aggiungi un immobile usando la stella nella sua scheda.</p>
        @endif
        <ul>
            @foreach ($this->favorites as $favorite)
                <li wire:key="favorite-{{ $favorite->id }}">
                    <div>
                        <h3>{{ $favorite->snapshot['label'] ?? '' }}</h3>
                        <p>{{ $favorite->snapshot['detail'] ?? '' }}</p>
                        @if (($favorite->snapshot['lat'] ?? null) !== null)
                            <a href="https://www.google.com/maps/search/?api=1&query={{ urlencode($favorite->snapshot['lat'].','.$favorite->snapshot['lng']) }}" target="_blank" rel="noopener noreferrer">Apri sulla mappa ↗</a>
                        @endif
                    </div>
                    <span class="reko-favorite-control"><button type="button" class="reko-favorite" aria-label="Rimuovi da Immobili salvati" title="Rimuovi da Immobili salvati" aria-pressed="true" wire:click="removeFavorite({{ $favorite->id }})"><x-trova.icon name="star" size="19" /><span>Salvato</span></button></span>
                </li>
            @endforeach
        </ul>
    </dialog>
</section>
