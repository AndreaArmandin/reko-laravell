<?php

use App\Models\CadastralUnit;
use App\Models\CadastralUnitVersion;
use App\Models\CatalogRelease;
use App\Models\Municipality;
use App\Models\MunicipalityCatalog;
use App\Models\Parcel;
use App\Trova\CatalogSearch;
use App\Trova\Categories;
use App\Trova\UnitFacts;

/*
 * Comune H001, a small hand-made catalogue for the Trova paths:
 *
 *   Fg 1 Part 1  VIA PO n. 1   palazzina: A/2 T, A/3 1, A/2 2 (4 vani each)
 *   Fg 1 Part 3  VIA PO n. 3   casa indipendente A/7 T-1 (6 vani) + box C/6 S1
 *   Fg 1 Part 5  VIA PO n. 5   box C/6 20 m² S1, C/6 25 m² T, C/6 18 m² S1-T
 *   Fg 2 Part 1  CORSO TO n. 2 negozio C/1 80 m², D/8, ufficio A/10 5 vani
 */
beforeEach(function () {
    $municipality = Municipality::query()->create(['cadastral_code' => 'H001', 'name' => 'Comune Case']);
    $release = CatalogRelease::query()->create(['municipality_id' => $municipality->id, 'code' => 'H001-1', 'label' => 'Attiva', 'status' => 'active']);
    MunicipalityCatalog::query()->create(['municipality_id' => $municipality->id, 'catalog_release_id' => $release->id]);

    $units = [
        ['1', '1', [['1', 'A/2', 4, 'VIA PO n. 1 Piano T'], ['2', 'A/3', 4, 'VIA PO n. 1 Piano 1'], ['3', 'A/2', 4, 'VIA PO n. 1 Piano 2']]],
        ['1', '3', [['1', 'A/7', 6, 'VIA PO n. 3 Piano T-1'], ['2', 'C/6', 15, 'VIA PO n. 3 Piano S1']]],
        ['1', '5', [['1', 'C/6', 20, 'VIA PO n. 5 Piano S1'], ['2', 'C/6', 25, 'VIA PO n. 5 Piano T'], ['3', 'C/6', 18, 'VIA PO n. 5 Piano S1-T']]],
        ['2', '1', [['1', 'C/1', 80, 'CORSO TO n. 2 Piano T'], ['2', 'D/8', null, 'CORSO TO n. 2 Piano T'], ['3', 'A/10', 5, 'CORSO TO n. 2 Piano 1']]],
    ];
    foreach ($units as [$sheet, $number, $list]) {
        $parcel = Parcel::query()->create(['municipality_id' => $municipality->id, 'cadastral_kind' => 'F', 'sheet' => $sheet, 'number' => $number]);
        foreach ($list as [$sub, $category, $value, $address]) {
            $unit = CadastralUnit::query()->create(['parcel_id' => $parcel->id, 'subalterno' => $sub]);
            CadastralUnitVersion::query()->create([
                'cadastral_unit_id' => $unit->id, 'catalog_release_id' => $release->id, 'status' => 'eligible',
                'category' => $category, 'search_group' => Categories::group($category), 'consistency' => $value,
                'consistency_unit' => $value === null ? null : Categories::dimensionUnit($category), 'address_raw' => $address,
            ]);
        }
    }

    app(UnitFacts::class)->build($release->id);
});

/**
 * @return list<string> "sheet/number:sub" of every matched unit
 */
function housingUnits(array $input): array
{
    $rows = (new CatalogSearch)->search(['code' => 'H001', 'sort' => 'address', ...$input])->rows;

    return array_merge(...array_map(fn ($row) => array_map(fn ($u) => $row['sheet'].'/'.$row['number'].':'.$u['sub'], $row['units']), $rows));
}

it('finds apartments only in parcels with several homes', function () {
    expect(housingUnits(['segment' => 'private', 'housing' => 'apartment', 'min' => 3, 'max' => 5]))->toBe(['1/1:1', '1/1:2', '1/1:3']);
});

it('finds vertical independent houses', function () {
    expect(housingUnits(['segment' => 'private', 'housing' => 'independent', 'min' => 1, 'max' => 10]))->toBe(['1/3:1']);
});

it('filters apartments by floor range or top floor', function () {
    $apartment = ['segment' => 'private', 'housing' => 'apartment', 'min' => 1, 'max' => 10];

    expect(housingUnits([...$apartment, 'floorMin' => 1, 'floorMax' => 1]))->toBe(['1/1:2'])
        ->and(housingUnits([...$apartment, 'floorMin' => 1]))->toBe(['1/1:2', '1/1:3'])
        ->and(housingUnits([...$apartment, 'topFloor' => true]))->toBe(['1/1:3']);
});

it('returns the v4 classification of each matched home', function () {
    $row = (new CatalogSearch)->search(['code' => 'H001', 'segment' => 'private', 'housing' => 'apartment', 'min' => 1, 'max' => 10])->rows[0];

    expect($row['units'][0]['housing'])->toBe(['esito' => 'APPARTAMENTO', 'conf' => 'alta', 'livelli_txt' => '1 livello', 'note' => ['abitazione sopra: sub 2, 3']])
        ->and($row['units'][0]['levels'])->toBe([0]);
});

it('finds garages on one exact floor only', function () {
    $garage = ['segment' => 'private', 'housing' => 'garage', 'min' => 1, 'max' => 100];

    expect(housingUnits([...$garage, 'exactFloor' => -1]))->toBe(['1/3:2', '1/5:1'])
        ->and(housingUnits([...$garage, 'exactFloor' => 0]))->toBe(['1/5:2']);
});

it('searches business activities with their own categories and measure', function () {
    expect(housingUnits(['segment' => 'business', 'activity' => 'shop', 'min' => 50, 'max' => 100]))->toBe(['2/1:1'])
        ->and(housingUnits(['segment' => 'business', 'activity' => 'shop', 'includeRelated' => true, 'min' => 50, 'max' => 100]))->toBe(['2/1:1', '2/1:2'])
        ->and(housingUnits(['segment' => 'business', 'activity' => 'office', 'min' => 4, 'max' => 6]))->toBe(['2/1:3'])
        ->and(housingUnits(['segment' => 'business', 'activity' => 'office', 'min' => 6, 'max' => 9]))->toBe([]);
});
