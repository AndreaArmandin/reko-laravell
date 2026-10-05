<?php

use App\Models\CadastralUnit;
use App\Models\CadastralUnitVersion;
use App\Models\CatalogRelease;
use App\Models\Municipality;
use App\Models\MunicipalityCatalog;
use App\Models\Parcel;
use App\Trova\CatalogSearch;
use App\Trova\CatalogSearchResult;
use App\Trova\Categories;
use App\Trova\SearchException;
use Illuminate\Support\Facades\DB;

/** Wide range: Trova requires both limits for homes, garages, shops and offices. */
const WIDE = ['min' => 0.5, 'max' => 1000];

/*
 * Hand-made catalogue of the fictitious Comune T001, active release "T001-NEW":
 *
 *   Fg 1 Part 10  VIA ROMA n. 1         A/2 5 vani, A/3 3 vani, C/6 20 m², A/2 9 vani (excluded), B/1 800 m³
 *   Fg 1 Part 20  VIA ROMAGNA n. 5      A/7 8 vani, C/6 35 m²
 *   Fg 2 Part 5   CORSO ITALIA n. 3     C/1 120 m², A/10 6 vani, D/1 (no measure)
 *   Fg 12 Part 7  VIA SANT'ANNA n. 2    A/4 (no measure), A/3 4 vani without subalterno
 *   Fg 3 Part 1   STAZIONE              E/1 only: never visible
 *
 * Plus an old release of T001 and another Comune: neither may ever appear.
 */
beforeEach(function () {
    $this->municipality = Municipality::query()->create(['cadastral_code' => 'T001', 'name' => 'Comune Test']);
    $old = CatalogRelease::query()->create(['municipality_id' => $this->municipality->id, 'code' => 'T001-OLD', 'label' => 'Vecchia', 'status' => 'archived']);
    $this->release = CatalogRelease::query()->create(['municipality_id' => $this->municipality->id, 'code' => 'T001-NEW', 'label' => 'Attiva', 'status' => 'active']);
    MunicipalityCatalog::query()->create(['municipality_id' => $this->municipality->id, 'catalog_release_id' => $this->release->id]);

    $roma = searchParcel($this->municipality, '1', '10', [7.55, 44.39]);
    searchUnit($roma, $this->release, '1', 'A/2', 5, 'VIA ROMA n. 1 Piano 1');
    searchUnit($roma, $this->release, '2', 'A/3', 3, 'VIA ROMA n. 1 Piano 2');
    searchUnit($roma, $this->release, '3', 'C/6', 20, 'VIA ROMA n. 1 Piano S1');
    searchUnit($roma, $this->release, '4', 'A/2', 9, 'VIA ROMA n. 1 Piano 3', 'excluded');
    searchUnit($roma, $this->release, '5', 'B/1', 800, 'VIA ROMA n. 1 Piano T');
    searchUnit($roma, $old, '9', 'A/2', 7, 'VIA ROMA n. 1 Piano 4'); // only in the old release

    $romagna = searchParcel($this->municipality, '1', '20');
    searchUnit($romagna, $this->release, '1', 'A/7', 8, 'VIA ROMAGNA n. 5 Piano T');
    searchUnit($romagna, $this->release, '2', 'C/6', 35, 'VIA ROMAGNA n. 5 Piano T');

    $italia = searchParcel($this->municipality, '2', '5');
    searchUnit($italia, $this->release, '1', 'C/1', 120, 'CORSO ITALIA n. 3 Piano T');
    searchUnit($italia, $this->release, '2', 'A/10', 6, 'CORSO ITALIA n. 3 Piano 1');
    searchUnit($italia, $this->release, '3', 'D/1', null, 'CORSO ITALIA n. 3 Piano T');

    $santAnna = searchParcel($this->municipality, '12', '7');
    searchUnit($santAnna, $this->release, '1', 'A/4', null, "VIA SANT'ANNA n. 2 Piano 1");
    searchUnit($santAnna, $this->release, null, 'A/3', 4, "VIA SANT'ANNA n. 2 Piano 2");

    $stazione = searchParcel($this->municipality, '3', '1');
    searchUnit($stazione, $this->release, '1', 'E/1', null, 'PIAZZALE STAZIONE');

    $other = Municipality::query()->create(['cadastral_code' => 'T002', 'name' => 'Altro Comune']);
    $otherRelease = CatalogRelease::query()->create(['municipality_id' => $other->id, 'code' => 'T002-NEW', 'label' => 'Altro', 'status' => 'active']);
    MunicipalityCatalog::query()->create(['municipality_id' => $other->id, 'catalog_release_id' => $otherRelease->id]);
    searchUnit(searchParcel($other, '1', '10'), $otherRelease, '1', 'A/2', 5, 'VIA ROMA n. 1 Piano 1');
});

/**
 * @param  array{0: float, 1: float}|null  $point  [longitude, latitude]
 */
function searchParcel(Municipality $municipality, string $sheet, string $number, ?array $point = null): Parcel
{
    $parcel = Parcel::query()->create([
        'municipality_id' => $municipality->id, 'cadastral_kind' => 'F', 'sheet' => $sheet, 'number' => $number,
    ]);

    if ($point !== null) {
        DB::insert(
            "insert into parcel_search_points (parcel_id, catalog_release_id, source, location, created_at, updated_at)
             values (?, ?, 'test', ST_SetSRID(ST_MakePoint(?, ?), 4326), now(), now())",
            [$parcel->id, $municipality->catalog()->firstOrFail()->catalog_release_id, ...$point],
        );
    }

    return $parcel;
}

function searchUnit(Parcel $parcel, CatalogRelease $release, ?string $sub, string $category, ?float $value, string $address, string $status = 'eligible'): void
{
    $unit = CadastralUnit::query()->firstOrCreate(
        ['parcel_id' => $parcel->id, 'subalterno' => $sub, 'source_ref' => $sub === null ? "test:{$parcel->id}:{$address}" : null],
    );

    CadastralUnitVersion::query()->create([
        'cadastral_unit_id' => $unit->id,
        'catalog_release_id' => $release->id,
        'status' => $status,
        'category' => $category,
        'search_group' => Categories::group($category),
        'consistency' => $value,
        'consistency_unit' => $value === null ? null : Categories::dimensionUnit($category),
        'address_raw' => $address,
    ]);
}

/**
 * @param  array<string, mixed>  $input
 */
function trovaSearch(array $input): CatalogSearchResult
{
    return (new CatalogSearch)->search(['code' => 'T001', 'pageSize' => 20, ...$input]);
}

/**
 * Parcels of a result as "sheet/number", in result order.
 *
 * @return list<string>
 */
function parcelsOf(CatalogSearchResult $result): array
{
    return array_map(fn (array $row) => $row['sheet'].'/'.$row['number'], $result->rows);
}

it('finds homes of the active release only, grouped by parcel', function () {
    $result = trovaSearch(['segment' => 'private', ...WIDE]);

    // Fg 2/5 has only A/10 and business units; Fg 3/1 only E/1; the excluded, B/1,
    // C/6, old-release and other-Comune units never count.
    expect($result->total)->toBe(3)
        ->and($result->matchedUnits)->toBe(4) // the A/4 without consistency cannot satisfy a range
        ->and(parcelsOf($result))->toEqualCanonicalizing(['1/10', '1/20', '12/7'])
        ->and($result->measure)->toBe('vani')
        ->and($result->pages())->toBe(1);
});

it('describes each parcel and its matching units', function () {
    $rows = collect(trovaSearch(['segment' => 'private', ...WIDE])->rows)->keyBy(fn ($row) => $row['sheet'].'/'.$row['number']);

    $roma = $rows['1/10'];
    expect($roma['address'])->toBe('VIA ROMA n. 1') // floor removed for Private
        ->and($roma['records'])->toBe(2)
        ->and($roma['identified'])->toBe(2)
        ->and((float) $roma['min_value'])->toBe(3.0)
        ->and((float) $roma['max_value'])->toBe(5.0)
        ->and($roma['categories'])->toBe(['A/2', 'A/3'])
        ->and($roma['latitude'])->toEqualWithDelta(44.39, 0.000001)
        ->and($roma['longitude'])->toEqualWithDelta(7.55, 0.000001)
        ->and(array_column($roma['units'], 'sub'))->toBe(['1', '2'])
        ->and($roma['units'][0])->toMatchArray(['category' => 'A/2', 'measure' => 'vani', 'address' => 'VIA ROMA n. 1 Piano 1']);

    $santAnna = $rows['12/7'];
    expect($santAnna['records'])->toBe(1) // the A/4 without consistency is out of any range
        ->and($santAnna['identified'])->toBe(0) // the remaining A/3 has no subalterno
        ->and((float) $santAnna['min_value'])->toBe(4.0)
        ->and($rows['1/20']['latitude'])->toBeNull(); // no known position
});

it('searches garages and business types within their categories', function () {
    $garages = trovaSearch(['segment' => 'private', 'housing' => 'garage', ...WIDE]);
    expect(parcelsOf($garages))->toEqualCanonicalizing(['1/10', '1/20'])
        ->and($garages->matchedUnits)->toBe(2)
        ->and($garages->measure)->toBe('m²');

    $business = trovaSearch(['segment' => 'business', 'sort' => 'address']);
    expect($business->total)->toBe(3) // C/6 counts as business "other"; B/1 and E/1 never
        ->and($business->matchedUnits)->toBe(5)
        ->and($business->measure)->toBeNull()
        ->and($business->rows[0]['min_value'])->toBeNull() // no comparable measure without an activity
        ->and($business->rows[0]['address'])->toBe('CORSO ITALIA n. 3 Piano 1'); // Business keeps the floor

    expect(parcelsOf(trovaSearch(['segment' => 'business', 'businessType' => 'shop', ...WIDE])))->toBe(['2/5'])
        ->and(parcelsOf(trovaSearch(['segment' => 'business', 'businessType' => 'office', 'min' => 5, 'max' => 1000])))->toBe(['2/5'])
        ->and(trovaSearch(['segment' => 'business', 'businessType' => 'shop', 'min' => 200, 'max' => 1000])->total)->toBe(0);
});

it('filters by consistency range and categories', function () {
    $range = trovaSearch(['segment' => 'private', 'min' => '4', 'max' => '6']);
    expect(parcelsOf($range))->toEqualCanonicalizing(['1/10', '12/7'])
        ->and($range->matchedUnits)->toBe(2); // 3 vani, 8 vani and the A/4 without measure are out

    expect(parcelsOf(trovaSearch(['segment' => 'private', 'min' => 8, 'max' => 1000])))->toBe(['1/20'])
        ->and(parcelsOf(trovaSearch(['segment' => 'private', 'categories' => ['a 07'], ...WIDE])))->toBe(['1/20'])
        ->and(trovaSearch(['segment' => 'private', 'categories' => ['A/2', 'A/3'], ...WIDE])->matchedUnits)->toBe(3);
});

it('matches whole street words and cadastral references', function () {
    expect(parcelsOf(trovaSearch(['segment' => 'private', 'address' => 'Roma', ...WIDE])))->toBe(['1/10'])
        ->and(parcelsOf(trovaSearch(['segment' => 'private', 'address' => 'romagna', ...WIDE])))->toBe(['1/20'])
        ->and(parcelsOf(trovaSearch(['segment' => 'private', 'address' => 'Sant’Anna', ...WIDE])))->toBe(['12/7'])
        ->and(trovaSearch(['segment' => 'private', 'address' => 'Rom', ...WIDE])->total)->toBe(0)
        ->and(parcelsOf(trovaSearch(['segment' => 'private', 'sheet' => '012', ...WIDE])))->toBe(['12/7'])
        ->and(parcelsOf(trovaSearch(['segment' => 'private', 'sheet' => '1', 'parcel' => '010', ...WIDE])))->toBe(['1/10'])
        ->and(trovaSearch(['segment' => 'private', 'section' => 'A', ...WIDE])->total)->toBe(0);
});

it('sorts like Trova', function (string $sort, array $expected) {
    expect(parcelsOf(trovaSearch(['segment' => 'private', 'sort' => $sort, ...WIDE])))->toBe($expected);
})->with([
    'largest first' => ['surface-desc', ['1/20', '1/10', '12/7']],   // max 8, 5, 4
    'smallest first' => ['surface-asc', ['1/10', '12/7', '1/20']],   // min 3, 4, 8
    'address' => ['address', ['1/10', '1/20', '12/7']],              // Roma, Romagna, Sant'Anna
    'most units' => ['best', ['1/10', '1/20', '12/7']],              // 2, then 1 and 1 by address
]);

it('pages results without overlaps', function () {
    for ($i = 1; $i <= 22; $i++) {
        searchUnit(searchParcel($this->municipality, '50', (string) $i), $this->release, '1', 'C/6', 10 + $i, "VIA NUOVA n. {$i}");
    }

    $pages = array_map(
        fn (int $page) => trovaSearch(['segment' => 'private', 'housing' => 'garage', 'pageSize' => 10, 'page' => $page, ...WIDE]),
        [1, 2, 3, 4],
    );

    expect($pages[0]->total)->toBe(24)
        ->and($pages[0]->pages())->toBe(3)
        ->and(array_map(fn ($page) => count($page->rows), $pages))->toBe([10, 10, 4, 0])
        ->and(array_unique(array_merge(...array_map('parcelsOf', $pages))))->toHaveCount(24)
        ->and($pages[3]->total)->toBe(24); // a page past the end still reports the total
});

it('needs a municipality with an active catalogue', function () {
    Municipality::query()->create(['cadastral_code' => 'T003', 'name' => 'Senza catalogo']);

    expect(fn () => trovaSearch(['code' => 'T003', 'segment' => 'private', ...WIDE]))->toThrow(SearchException::class, 'Seleziona un Comune con archivio disponibile.')
        ->and(fn () => trovaSearch(['code' => 'Z999', 'segment' => 'private', ...WIDE]))->toThrow(SearchException::class, 'Seleziona un Comune con archivio disponibile.');
});
