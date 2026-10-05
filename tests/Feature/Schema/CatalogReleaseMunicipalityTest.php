<?php

use App\Models\CadastralUnit;
use App\Models\CadastralUnitVersion;
use App\Models\CatalogRelease;
use App\Models\Municipality;
use App\Models\MunicipalityCatalog;
use App\Models\Parcel;
use App\Models\ParcelHousingContext;
use App\Trova\CatalogSearch;
use Database\Seeders\DemoCatalogSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * @return array{0: Municipality, 1: CatalogRelease, 2: Parcel}
 */
function municipalityWithRelease(string $code, string $name): array
{
    $municipality = Municipality::query()->create(['cadastral_code' => $code, 'name' => $name]);
    $release = CatalogRelease::query()->create([
        'municipality_id' => $municipality->id, 'code' => "{$code}-1", 'label' => $name, 'status' => 'active',
    ]);
    $parcel = Parcel::query()->create([
        'municipality_id' => $municipality->id, 'cadastral_kind' => 'F', 'sheet' => '1', 'number' => '10',
    ]);

    return [$municipality, $release, $parcel];
}

function rejected(callable $write): void
{
    expect(fn () => DB::transaction($write))->toThrow(QueryException::class, 'REKO:');
}

it('accepts catalogue rows of the release municipality', function () {
    [$cuneo, $release, $parcel] = municipalityWithRelease('D205', 'Cuneo');
    $unit = CadastralUnit::query()->create(['parcel_id' => $parcel->id, 'subalterno' => '1']);

    CadastralUnitVersion::query()->create(['cadastral_unit_id' => $unit->id, 'catalog_release_id' => $release->id, 'category' => 'A/2']);
    MunicipalityCatalog::query()->create(['municipality_id' => $cuneo->id, 'catalog_release_id' => $release->id]);
    DB::insert("insert into parcel_search_points (parcel_id, catalog_release_id, source, location, created_at, updated_at)
        values (?, ?, 'test', ST_SetSRID(ST_MakePoint(7.55, 44.39), 4326), now(), now())", [$parcel->id, $release->id]);

    expect(CadastralUnitVersion::query()->count())->toBe(1);
});

it('refuses catalogue rows that mix two municipalities', function () {
    [$cuneo, $cuneoRelease, $cuneoParcel] = municipalityWithRelease('D205', 'Cuneo');
    [$milano, $milanoRelease] = municipalityWithRelease('F205', 'Milano');
    $unit = CadastralUnit::query()->create(['parcel_id' => $cuneoParcel->id, 'subalterno' => '1']);

    rejected(fn () => CadastralUnitVersion::query()->create(['cadastral_unit_id' => $unit->id, 'catalog_release_id' => $milanoRelease->id]));
    rejected(fn () => MunicipalityCatalog::query()->create(['municipality_id' => $cuneo->id, 'catalog_release_id' => $milanoRelease->id]));
    rejected(fn () => DB::insert("insert into parcel_search_points (parcel_id, catalog_release_id, source, location, created_at, updated_at)
        values (?, ?, 'test', ST_SetSRID(ST_MakePoint(7.55, 44.39), 4326), now(), now())", [$cuneoParcel->id, $milanoRelease->id]));
    rejected(fn () => ParcelHousingContext::query()->create([
        'catalog_release_id' => $milanoRelease->id, 'parcel_id' => $cuneoParcel->id, 'generation' => str_repeat('a', 64),
        'known_units' => 1, 'unknown_units' => 0, 'housing_context' => 'single',
    ]));
    rejected(fn () => DB::table('parcel_versions')->insert([
        'parcel_id' => $cuneoParcel->id, 'catalog_release_id' => $milanoRelease->id, 'created_at' => now(), 'updated_at' => now(),
    ]));

    // An existing correct row cannot be moved to the other release either.
    $version = CadastralUnitVersion::query()->create(['cadastral_unit_id' => $unit->id, 'catalog_release_id' => $cuneoRelease->id]);
    rejected(fn () => $version->update(['catalog_release_id' => $milanoRelease->id]));
});

it('keeps parcels, units and releases in their municipality', function () {
    [, $release, $parcel] = municipalityWithRelease('D205', 'Cuneo');
    [$milano, , $milanoParcel] = municipalityWithRelease('F205', 'Milano');
    $unit = CadastralUnit::query()->create(['parcel_id' => $parcel->id, 'subalterno' => '1']);

    rejected(fn () => $parcel->update(['municipality_id' => $milano->id]));
    rejected(fn () => $release->update(['municipality_id' => $milano->id]));
    rejected(fn () => $unit->update(['parcel_id' => $milanoParcel->id]));

    // Other columns stay editable (fresh(): the refused change is still dirty in memory).
    $parcel = $parcel->fresh();
    $parcel->update(['section' => 'A']);
    expect($parcel->fresh()->section)->toBe('A');
});

it('still seeds and searches the demo catalogue', function () {
    $this->seed(DemoCatalogSeeder::class);

    $result = (new CatalogSearch)->search(['code' => 'X001', 'segment' => 'private']);

    expect($result->total)->toBe(272)
        ->and($result->matchedUnits)->toBe(1856);
});
