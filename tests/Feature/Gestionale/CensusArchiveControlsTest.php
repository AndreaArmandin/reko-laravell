<?php

use App\Gestionale\Actions\Census\AssociateCensusOwner;
use App\Gestionale\Actions\Census\RemoveCensusOwnership;
use App\Gestionale\Census\CensusImporter;
use App\Gestionale\Census\CensusReader;
use App\Models\AgencyMembership;
use App\Models\Contact;
use App\Models\Municipality;
use Livewire\Livewire;

function censusArchiveUnit(AgencyMembership $admin): int
{
    $provinceId = DB::table('territorial_provinces')->insertGetId([
        'code' => '015', 'abbreviation' => 'MI', 'name' => 'Milano', 'created_at' => now(), 'updated_at' => now(),
    ]);
    Municipality::query()->create(['cadastral_code' => 'F205', 'name' => 'Milano', 'territorial_province_id' => $provinceId]);
    $result = app(CensusImporter::class)->apply($admin, [
        'sourceText' => implode("\t", ['309', '387', '1', 'VIA ROMA n. 5 Piano T', '2', 'A/3', '3', '4,5 vani', 'Euro: 523,10', '1234']),
        'context' => ['kind' => 'Fabbricati', 'province' => 'MI', 'municipality' => 'Milano', 'code' => 'F205', 'section' => '', 'situationDate' => ''],
        'confirmUpdates' => true,
        'skipInvalid' => true,
    ]);

    return $result['unitIds'][0];
}

it('associates and unlinks an owner while keeping the ownership history visible', function () {
    $admin = AgencyMembership::factory()->admin()->create();
    $this->actingAs($admin->user);
    $unitId = censusArchiveUnit($admin);
    $owner = Contact::factory()->owner()->create(['agency_id' => $admin->agency_id, 'display_name' => 'Mario Rossi']);

    app(AssociateCensusOwner::class)->handle($admin, $unitId, $owner->id, [
        'right' => 'Proprietà', 'fraction' => '1/2', 'source' => 'Visura aggiornata', 'effectiveDate' => '2025-01-15', 'note' => 'Prima associazione',
    ]);
    app(RemoveCensusOwnership::class)->unlink($admin, $unitId, $owner->id, [
        'reason' => 'Trasferimento verificato', 'effectiveDate' => '2026-04-02', 'correction' => false, 'confirmed' => true,
    ]);

    $entry = (new CensusReader($admin))->entries(['unitId' => $unitId])->sole();
    expect($entry['owners'])->toBe([])
        ->and($entry['holdings'])->toHaveCount(1)
        ->and($entry['holdings'][0]['owner'])->toBe('Mario Rossi')
        ->and($entry['holdings'][0]['current'])->toBeFalse()
        ->and($entry['holdings'][0]['end'])->toBe('2026-04-02')
        ->and($entry['holdings'][0]['reason'])->toBe('Trasferimento verificato');

    Livewire::test('pages::gestionale.archive.index')->set('municipalityCode', 'F205')
        ->assertSee('census-unit-record', false)->assertSee('Storico catastale e attività')->assertSee('Trasferimento verificato')
        ->assertDontSee('crm-cadastral-table', false);
});

it('hides removed archive filters and ignores legacy filter values', function () {
    $admin = AgencyMembership::factory()->admin()->create();
    $this->actingAs($admin->user);
    censusArchiveUnit($admin);

    $page = Livewire::test('pages::gestionale.archive.index')
        ->assertDontSee('Combinazioni salvate')
        ->assertDontSee('Operatore assegnato')
        ->assertDontSee('Stato catastale')
        ->assertDontSee('Perimetro acquisito')
        ->assertDontSee('Zona censuaria')
        ->assertDontSee('Origine')
        ->set('municipalityCode', 'F205')
        ->set('mode', 'catastale')->set('sheet', '309')->set('parcel', '387')->set('sub', '1')
        ->set('filterValues.section', 'SEZIONE-INESISTENTE')
        ->set('filterValues.zone', 'ZONA-INESISTENTE')
        ->set('filterValues.operator', '999999')
        ->set('filterValues.state', 'Soppresso')
        ->set('filterValues.geometry', 'Sì')
        ->set('filterValues.source', 'non-esistente')
        ->assertSee('VIA ROMA n. 5');

    expect(DB::table('census_saved_filters')->where('user_id', $admin->user_id)->count())->toBe(0);
});
