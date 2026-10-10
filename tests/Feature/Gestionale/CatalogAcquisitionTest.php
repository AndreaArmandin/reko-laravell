<?php

use App\Gestionale\Actions\Scouting\AcquireCatalogParcels;
use App\Gestionale\CommandRejected;
use App\Models\AgencyMembership;
use App\Models\CadastralUnit;
use App\Models\CadastralUnitVersion;
use App\Models\CatalogRelease;
use App\Models\Municipality;
use App\Models\MunicipalityCatalog;
use App\Models\Parcel;

function catalogAcquisitionFixture(): array
{
    $municipality = Municipality::query()->create(['cadastral_code' => 'X001', 'name' => 'Comune Demo']);
    $release = CatalogRelease::query()->create(['municipality_id' => $municipality->id, 'code' => 'TEST-X001', 'label' => 'Catalogo test', 'released_on' => '2026-10-01', 'status' => 'active']);
    MunicipalityCatalog::query()->create(['municipality_id' => $municipality->id, 'catalog_release_id' => $release->id, 'activated_at' => now()]);
    $parcel = Parcel::query()->create(['municipality_id' => $municipality->id, 'cadastral_kind' => 'F', 'section' => '', 'sheet' => '1', 'number' => '10']);
    $key = '["X001","Fabbricati","","1","10","1"]';
    $unit = CadastralUnit::query()->create(['parcel_id' => $parcel->id, 'subalterno' => '1', 'legacy_key' => $key]);
    CadastralUnitVersion::query()->create([
        'cadastral_unit_id' => $unit->id, 'catalog_release_id' => $release->id, 'status' => 'eligible',
        'category' => 'A/2', 'class' => '3', 'consistency' => 4.5, 'consistency_unit' => 'vani',
        'rendita' => 520.50, 'address_raw' => 'VIA DEMO n. 10 Piano T', 'floor' => 'T', 'census_zone' => '1',
    ]);

    return [$municipality, $release, $parcel, $unit, $key];
}

it('acquires eligible catalogue units once and preserves catalogue provenance', function () {
    $admin = AgencyMembership::factory()->admin()->create();
    $this->actingAs($admin->user);
    [, , $parcel, $unit, $key] = catalogAcquisitionFixture();

    $input = ['parcels' => [['code' => 'X001', 'section' => '', 'sheet' => '01', 'parcel' => '010']], 'token' => 'catalog-acquire-1'];
    $first = app(AcquireCatalogParcels::class)->handle($admin, $input);
    $second = app(AcquireCatalogParcels::class)->handle($admin, $input);
    $observation = DB::table('agency_unit_observations')->where('agency_id', $admin->agency_id)->where('cadastral_unit_id', $unit->id)->sole();

    expect($first['units'])->toBe(1)->and($second)->toEqual($first)
        ->and($observation->source)->toBe('catalog')->and($observation->catalog_key)->toBe($key)
        ->and($observation->category)->toBe('A/2')->and($observation->address)->toBe('VIA DEMO n. 10 Piano T')
        ->and($observation->census_batch_id)->not->toBeNull()
        ->and(DB::table('ownerships')->where('agency_id', $admin->agency_id)->count())->toBe(0)
        ->and(DB::table('scouting_assignments')->where('agency_id', $admin->agency_id)->where('parcel_id', $parcel->id)->count())->toBe(0);
});

it('applies the operator package limit and assigns acquired parcels to the scout', function () {
    $admin = AgencyMembership::factory()->admin()->create();
    $scout = AgencyMembership::factory()->scout()->create([
        'agency_id' => $admin->agency_id,
        'catalog_package' => ['name' => 'Base', 'enabled' => true, 'maxParcels' => 1, 'unlimited' => false],
    ]);
    $this->actingAs($scout->user);
    [, , $parcel] = catalogAcquisitionFixture();

    $result = app(AcquireCatalogParcels::class)->handle($scout, ['parcels' => [['code' => 'X001', 'section' => '', 'sheet' => '1', 'parcel' => '10']]]);

    expect($result['parcels'])->toBe(1)
        ->and(DB::table('scouting_assignments')->where('agency_id', $admin->agency_id)->where('user_id', $scout->user_id)->where('parcel_id', $parcel->id)->exists())->toBeTrue();

    $secondParcel = Parcel::query()->create(['municipality_id' => DB::table('municipalities')->where('cadastral_code', 'X001')->value('id'), 'cadastral_kind' => 'F', 'section' => '', 'sheet' => '1', 'number' => '11']);
    $releaseId = DB::table('municipality_catalogs')->where('municipality_id', $secondParcel->municipality_id)->value('catalog_release_id');
    $secondUnit = CadastralUnit::query()->create(['parcel_id' => $secondParcel->id, 'subalterno' => '1', 'legacy_key' => '["X001","Fabbricati","","1","11","1"]']);
    CadastralUnitVersion::query()->create(['cadastral_unit_id' => $secondUnit->id, 'catalog_release_id' => $releaseId, 'status' => 'eligible', 'category' => 'A/3']);

    expect(fn () => app(AcquireCatalogParcels::class)->handle($scout, ['parcels' => [['code' => 'X001', 'section' => '', 'sheet' => '1', 'parcel' => '11']]]))
        ->toThrow(CommandRejected::class, 'Il limite di particelle del pacchetto non consente questa acquisizione.');
});

it('keeps the package gate on the server', function () {
    $admin = AgencyMembership::factory()->admin()->create();
    $scout = AgencyMembership::factory()->scout()->create(['agency_id' => $admin->agency_id]);
    $this->actingAs($scout->user);
    catalogAcquisitionFixture();

    expect(fn () => app(AcquireCatalogParcels::class)->handle($scout, ['parcels' => [['code' => 'X001', 'section' => '', 'sheet' => '1', 'parcel' => '10']]]))
        ->toThrow(CommandRejected::class, 'Acquisizione del catalogo non abilitata per il tuo pacchetto.');
});
