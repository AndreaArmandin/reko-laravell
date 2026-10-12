<?php

use App\Gestionale\Scouting\ScoutingMap;
use App\Models\AgencyMembership;
use App\Models\CadastralUnit;
use App\Models\CadastralUnitVersion;
use App\Models\CatalogRelease;
use App\Models\Municipality;
use App\Models\MunicipalityCatalog;
use App\Models\Parcel;
use Livewire\Livewire;

function mapParcelFixture(string $code = 'X001'): Parcel
{
    $municipality = Municipality::query()->create(['cadastral_code' => $code, 'name' => 'Comune Demo']);
    $release = CatalogRelease::query()->create(['municipality_id' => $municipality->id, 'code' => "TEST-{$code}", 'label' => 'Catalogo test', 'released_on' => '2026-10-01', 'status' => 'active']);
    MunicipalityCatalog::query()->create(['municipality_id' => $municipality->id, 'catalog_release_id' => $release->id, 'activated_at' => now()]);
    $parcel = Parcel::query()->create(['municipality_id' => $municipality->id, 'cadastral_kind' => 'F', 'section' => '', 'sheet' => '1', 'number' => '10']);
    $unit = CadastralUnit::query()->create(['parcel_id' => $parcel->id, 'subalterno' => '1', 'legacy_key' => "[\"{$code}\",\"Fabbricati\",\"\",\"1\",\"10\",\"1\"]"]);
    CadastralUnitVersion::query()->create([
        'cadastral_unit_id' => $unit->id, 'catalog_release_id' => $release->id, 'status' => 'eligible',
        'category' => 'A/2', 'consistency' => 4.5, 'consistency_unit' => 'vani', 'address_raw' => 'VIA DEMO n. 10',
    ]);

    return $parcel;
}

beforeEach(function () {
    $this->admin = AgencyMembership::factory()->admin()->create();
    $this->scout = AgencyMembership::factory()->scout()->create(['agency_id' => $this->admin->agency_id]);
    $this->other = AgencyMembership::factory()->scout()->create(['agency_id' => $this->admin->agency_id]);
});

it('reaches the census data of a parcel only through the role scope', function () {
    $parcel = mapParcelFixture();

    // Nobody has a link to the parcel yet: not even the administrator reaches census data of it.
    expect(ScoutingMap::accessible($this->admin, $parcel->id))->toBeFalse()
        ->and(ScoutingMap::accessible($this->scout, $parcel->id))->toBeFalse();

    DB::table('scouting_assignments')->insert([
        'agency_id' => $this->admin->agency_id, 'user_id' => $this->scout->user_id, 'parcel_id' => $parcel->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(ScoutingMap::accessible($this->scout, $parcel->id))->toBeTrue()
        ->and(ScoutingMap::accessible($this->other, $parcel->id))->toBeFalse()
        ->and(ScoutingMap::accessible($this->admin, $parcel->id))->toBeTrue();
});

it('does not show agency census counts for a parcel picked on the map that the operator cannot reach', function () {
    $parcel = mapParcelFixture();
    $this->actingAs($this->other->user);

    Livewire::test('pages::gestionale.scouting.index')
        ->set('municipalityCode', 'X001')
        ->dispatch('map-outline-selected', key: 'scout-map', outline: ['code' => 'X001', 'kind' => 'F', 'section' => '', 'sheet' => '1', 'parcel' => '10'])
        ->assertSee('Nessun dato del censimento accessibile per questa particella.')
        ->assertDontSee('immobili in archivio');
});

it('does not let the Comune change while a zone is being drawn', function () {
    mapParcelFixture();
    $this->actingAs($this->admin->user);

    Livewire::test('pages::gestionale.scouting.index')
        ->set('municipalityCode', 'X001')
        ->call('startDraw', false)
        ->assertSet('drawing', true)
        ->set('municipalityCode', 'F205')
        ->assertSet('municipalityCode', 'X001')
        ->assertSet('drawMunicipalityCode', 'X001');
});
