<?php

use App\Gestionale\Census\CensusImporter;
use App\Models\AgencyMembership;
use App\Models\Municipality;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

it('loads the archive page and exposes SISTER import to the administrator', function () {
    $admin = AgencyMembership::factory()->admin()->create();
    $this->actingAs($admin->user);

    Livewire::test('pages::gestionale.archive.index')
        ->assertSee('Archivio catastale')
        ->assertSee('Esporta CF / P.IVA senza telefono · CSV')
        ->assertSee('Nessuna unità censita')
        ->assertSee('Importa da SISTER')
        ->call('openSisterImport')
        ->assertSee('Testo immobili')
        ->assertSee('Testo proprietari')
        ->assertSee('Controlla il testo');
});

it('opens the archive in the owners view from the dashboard shortcut', function () {
    $admin = AgencyMembership::factory()->admin()->create();
    $this->actingAs($admin->user);

    Livewire::withQueryParams(['vista' => 'Proprietari'])->test('pages::gestionale.archive.index')
        ->assertSet('view', 'Proprietari');
});

it('keeps SISTER import hidden and blocks its actions for a scout without the permission', function () {
    $admin = AgencyMembership::factory()->admin()->create();
    $scout = AgencyMembership::factory()->scout()->create(['agency_id' => $admin->agency_id]);
    $this->actingAs($scout->user);

    Livewire::test('pages::gestionale.archive.index')
        ->assertDontSee('Importa da SISTER')
        ->call('openSisterImport')
        ->assertForbidden();
});

it('previews and imports SISTER text through the archive interface', function () {
    $admin = AgencyMembership::factory()->admin()->create();
    $this->actingAs($admin->user);
    $provinceId = DB::table('territorial_provinces')->insertGetId([
        'code' => '015', 'abbreviation' => 'MI', 'name' => 'Milano', 'created_at' => now(), 'updated_at' => now(),
    ]);
    Municipality::query()->create(['cadastral_code' => 'F205', 'name' => 'Milano', 'territorial_province_id' => $provinceId]);
    $estate = implode("\t", ['309', '387', '1', 'VIA ROMA n. 5 Piano T', '2', 'A/3', '3', '4,5 vani', 'Euro: 523,10', '1234']);

    $utf16 = "\xFF\xFE".mb_convert_encoding($estate, 'UTF-16LE', 'UTF-8');
    Livewire::test('pages::gestionale.archive.index')
        ->call('openSisterImport')
        ->set('sisterProvince', 'MI')
        ->set('sisterMunicipality', 'Milano')
        ->set('sisterCode', 'F205')
        ->set('sisterEstateFile', UploadedFile::fake()->createWithContent('immobili.txt', $utf16))
        ->call('loadSisterTextFile', 'estates')
        ->assertSet('sisterEstatesText', $estate)
        ->call('previewSisterImport')
        ->assertSet('sisterError', '')
        ->assertSee('Aggiorna anteprima')
        ->assertSee('F. 309 · P. 387 · Sub. 1')
        ->call('applySisterImport')
        ->assertSet('sisterResult.title', 'Importato 1 immobile')
        ->assertSee('Importato 1 immobile')
        ->call('openImportedSisterResults')
        ->assertSet('sisterImportOpen', false)
        ->assertSet('municipalityCode', 'F205')
        ->assertSet('view', 'Immobili')
        ->assertSet('sheet', '309')
        ->assertSet('parcel', '387')
        ->assertSee('VIA ROMA n. 5');
});

it('shows full paged unit records when expanding an archive owner', function () {
    $admin = AgencyMembership::factory()->admin()->create();
    $this->actingAs($admin->user);
    $provinceId = DB::table('territorial_provinces')->insertGetId([
        'code' => '015', 'abbreviation' => 'MI', 'name' => 'Milano', 'created_at' => now(), 'updated_at' => now(),
    ]);
    Municipality::query()->create(['cadastral_code' => 'F205', 'name' => 'Milano', 'territorial_province_id' => $provinceId]);
    $context = ['kind' => 'Fabbricati', 'province' => 'MI', 'municipality' => 'Milano', 'code' => 'F205', 'section' => '', 'situationDate' => ''];
    $estate = implode("\t", ['309', '387', '1', 'VIA ROMA n. 5 Piano T', '2', 'A/3', '3', '4,5 vani', 'Euro: 523,10', '1234']);
    $import = app(CensusImporter::class)->apply($admin, ['sourceText' => $estate, 'context' => $context, 'confirmUpdates' => true, 'skipInvalid' => true]);
    app(CensusImporter::class)->apply($admin, [
        'sourceText' => implode("\t", ['ROSSI MARIO', 'RSSMRA80A01F205X', "Proprieta' per 1/1"]),
        'targetUnitId' => $import['unitIds'][0], 'context' => $context, 'confirmUpdates' => true, 'skipInvalid' => true,
    ]);
    $ownerId = DB::table('contacts')->where('agency_id', $admin->agency_id)->where('tax_code', 'RSSMRA80A01F205X')->value('id');

    Livewire::test('pages::gestionale.archive.index')
        ->set('municipalityCode', 'F205')
        ->set('view', 'Proprietari')
        ->assertSee('Mostra immobili (1)')
        ->call('showOwnerProperties', (int) $ownerId)
        ->assertSee('VIA ROMA n. 5')
        ->assertSee('Foglio')
        ->assertSee('Proprietà')
        ->assertSee('Pagine immobili del proprietario')
        ->assertSee('Dati di provenienza e testo originale');
});

it('edits an owner contact inline on the unit record instead of opening a dialog', function () {
    $admin = AgencyMembership::factory()->admin()->create();
    $this->actingAs($admin->user);
    $provinceId = DB::table('territorial_provinces')->insertGetId([
        'code' => '015', 'abbreviation' => 'MI', 'name' => 'Milano', 'created_at' => now(), 'updated_at' => now(),
    ]);
    Municipality::query()->create(['cadastral_code' => 'F205', 'name' => 'Milano', 'territorial_province_id' => $provinceId]);
    $context = ['kind' => 'Fabbricati', 'province' => 'MI', 'municipality' => 'Milano', 'code' => 'F205', 'section' => '', 'situationDate' => ''];
    $unit = app(CensusImporter::class)->apply($admin, [
        'sourceText' => implode("\t", ['309', '387', '1', 'VIA ROMA n. 5 Piano T', '2', 'A/3', '3', '4,5 vani', 'Euro: 523,10', '1234']),
        'context' => $context,
        'confirmUpdates' => true,
        'skipInvalid' => true,
    ]);
    app(CensusImporter::class)->apply($admin, [
        'sourceText' => implode("\t", ['ROSSI MARIO', 'RSSMRA80A01F205X', "Proprieta' per 1/1"]),
        'targetUnitId' => $unit['unitIds'][0],
        'context' => $context,
        'confirmUpdates' => true,
        'skipInvalid' => true,
    ]);
    $ownerId = (int) DB::table('contacts')->where('agency_id', $admin->agency_id)->where('tax_code', 'RSSMRA80A01F205X')->value('id');

    Livewire::test('pages::gestionale.archive.index')
        ->set('municipalityCode', 'F205')
        ->assertSee('Aggiungi recapito')
        ->assertDontSee('owner-contact-title')
        ->call('editOwnerContact', $ownerId, $unit['unitIds'][0])
        ->assertSee('census-inline-editor', false)
        ->assertSee('Recapito')
        ->assertDontSee('<dialog', false)
        ->set('contactDraft', '+39 333 555 0199')
        ->call('saveOwnerContact')
        ->assertDispatched('crm-notice')
        ->assertSee('+39 333 555 0199');
});

it('requires choosing the municipality before exposing archive filters and results', function () {
    $admin = AgencyMembership::factory()->admin()->create();
    $this->actingAs($admin->user);

    $provinceId = DB::table('territorial_provinces')->insertGetId([
        'code' => '015', 'abbreviation' => 'MI', 'name' => 'Milano', 'created_at' => now(), 'updated_at' => now(),
    ]);
    Municipality::query()->create([
        'cadastral_code' => 'F205', 'name' => 'Milano', 'territorial_province_id' => $provinceId,
    ]);
    app(CensusImporter::class)->apply($admin, [
        'sourceText' => implode("\t", ['309', '387', '1', 'VIA ROMA n. 5 Piano T', '2', 'A/3', '3', '4,5 vani', 'Euro: 523,10', '1234']),
        'context' => ['kind' => 'Fabbricati', 'province' => 'MI', 'municipality' => 'Milano', 'code' => 'F205', 'section' => '', 'situationDate' => ''],
        'confirmUpdates' => true,
        'skipInvalid' => true,
    ]);

    $page = Livewire::test('pages::gestionale.archive.index')
        ->assertSet('mode', 'indirizzo')
        ->assertSee('Comune')
        ->assertSee('Seleziona il Comune per aprire i filtri e le schede dell’archivio.')
        ->assertDontSee('Ricerca libera')
        ->assertDontSee('VIA ROMA n. 5');

    $page->set('municipalitySearch', 'Mila')
        ->assertSee('role="combobox"', false)
        ->assertSee('role="listbox"', false)
        ->assertSee('Milano')
        ->call('chooseMunicipalityOption')
        ->assertSet('municipalityCode', 'F205')
        ->assertSet('municipalitySearch', 'Milano')
        ->assertSee('Ricerca libera')
        ->assertSee('Filtri avanzati')
        ->assertSee('Riferimenti catastali')
        ->assertSee('Mostra prima')
        ->assertSee('VIA ROMA n. 5')
        ->assertSee('A/3')
        ->assertSee('Registra attività')
        ->assertSee('Storico attività')
        ->assertSee('Importa da SISTER')
        ->assertDontSee('Risultati per pagina');

    $page->set('order', 'Esito')
        ->assertSee('Esito da mostrare per primo')
        ->set('firstOutcome', 'Non risponde')
        ->set('mode', 'catastale')
        ->assertSee('Per avviare la ricerca catastale')
        ->assertDontSee('VIA ROMA n. 5')
        ->set('sheet', '309')
        ->set('parcel', '387')
        ->set('sub', '1')
        ->assertSee('VIA ROMA n. 5')
        ->call('resetFilters')
        ->assertSet('municipalityCode', '')
        ->assertSet('mode', 'indirizzo')
        ->assertSet('firstOutcome', 'Interessato')
        ->assertSee('Seleziona il Comune per aprire i filtri e le schede dell’archivio.');
});
