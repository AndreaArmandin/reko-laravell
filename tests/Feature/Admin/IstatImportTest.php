<?php

use App\Models\Municipality;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

it('imports the ISTAT list, fills the province of existing Comuni and suggests them when adding one', function () {
    $torino = Municipality::query()->create(['cadastral_code' => 'L219', 'name' => 'Torino']);
    $csv = tempnam(sys_get_temp_dir(), 'istat').'.csv';
    file_put_contents($csv, implode("\n", [
        "Codice Regione;Codice dell'Unità territoriale sovracomunale (valida a fini statistici);Codice Comune formato alfanumerico;Denominazione in italiano;Denominazione Regione;Denominazione dell'Unità territoriale sovracomunale (valida a fini statistici);Sigla automobilistica;Codice Catastale del Comune",
        '01;201;001272;Torino;Piemonte;Torino;TO;L219',
        '01;201;001001;Agliè;Piemonte;Torino;TO;A074',
        '03;215;015146;Milano;Lombardia;Milano;MI;F205',
    ]));

    $this->artisan('reko:import-istat', ['--file' => $csv])->assertSuccessful()->expectsOutputToContain('Comuni 3');
    $this->artisan('reko:import-istat', ['--file' => $csv])->assertSuccessful();   // idempotent

    expect(DB::table('territorial_municipalities')->count())->toBe(3)
        ->and(DB::table('territorial_provinces')->count())->toBe(2)
        ->and($torino->fresh()->province->abbreviation)->toBe('TO')
        ->and(Municipality::query()->count())->toBe(1);   // the national list does not become platform Comuni

    $this->actingAs(User::factory()->create(['is_admin' => true]));
    Livewire::test('pages::admin.catalog.index')->set('adding', true)->set('lookup', 'mil')->assertSee('Milano (MI)')
        ->call('pick', 'F205')->assertSet('name', 'Milano')->assertSet('code', 'F205')
        ->call('addMunicipality')->assertHasNoErrors();
    expect(Municipality::query()->where('cadastral_code', 'F205')->first()->province->abbreviation)->toBe('MI');
});
