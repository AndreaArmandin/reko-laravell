<?php

use App\Models\TrovaFavorite;
use App\Models\TrovaSearchHistoryEntry;
use App\Models\TrovaSavedSearch;
use App\Models\AgencyMembership;
use App\Models\CadastralUnit;
use App\Models\CadastralUnitVersion;
use App\Models\CatalogRelease;
use App\Models\Municipality;
use App\Models\MunicipalityCatalog;
use App\Models\Parcel;
use App\Models\User;
use Database\Seeders\DemoOsmCatalogSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(DemoOsmCatalogSeeder::class);
    $this->actingAs($this->user = User::factory()->create());
});

it('opens the Trova start page for signed-in users only', function () {
    $this->get(route('trova'))->assertOk()
        ->assertSee('In quale Comune vuoi cercare?')
        ->assertSee('Cosa cerchi?')
        ->assertSee('Locali e spazi per attività');

    auth()->logout();
    $this->get(route('trova'))->assertRedirect(route('login'));
});

it('walks the homes path to apartment results', function () {
    $page = Livewire::test('pages::trova.index')
        ->call('next')->assertSet('step', 'municipality') // no Comune yet
        ->set('code', 'X002')->call('choose', 'homes')
        ->call('next')->assertSet('step', 'zone')
        ->call('next')->assertSet('step', 'zone')->assertSee('Scegli come cercare la zona.')
        ->call('setZoneMethod', 'all')
        ->call('next')->assertSet('step', 'type')->assertSee('Che abitazione cerchi?')
        ->call('next')->assertSee('Scegli il tipo di immobile o l’attività da cercare.')
        ->call('setHousing', 'apartment')
        ->call('next')->assertSee('Indica entrambi i valori in vani: Da e A.')
        ->set('min', '3')->set('max', '5')
        ->call('next')
        ->assertSet('step', 'results')
        ->assertSet('error', null)
        ->assertSee('particelle presenti')
        ->assertSee('APPARTAMENTO')
        ->assertSee('La tua richiesta:');

    expect($page->get('total'))->toBeGreaterThan(0)
        ->and(TrovaSearchHistoryEntry::query()->where('user_id', $this->user->id)->count())->toBe(1);
});

it('searches garages around a point', function () {
    Livewire::test('pages::trova.index')
        ->set('code', 'X002')->call('choose', 'garage')->call('next')
        ->call('setZoneMethod', 'map')
        ->call('next')->assertSee('Tocca la mappa per scegliere un punto.')
        ->call('setPoint', 44.3895, 7.5470)->set('radius', 1000)
        ->call('next')->assertSet('step', 'review')->assertSee('Superficie · m² obbligatori')
        ->set('min', '10')->set('max', '30')
        ->call('next')
        ->assertSet('step', 'results')
        ->assertSee('Raggio 1 km')
        ->assertSee('Box auto');
});

it('enables neighborhood and drawing choices only for a municipality with zones', function () {
    $municipalityId = DB::table('municipalities')->where('cadastral_code', 'X002')->value('id');
    $zoneId = DB::table('geographic_zones')->insertGetId([
        'municipality_id' => $municipalityId,
        'code' => 'CENTRO-TEST',
        'name' => 'Centro test',
        'kind' => 'quartiere',
        'indicative' => true,
        'source' => 'test fixture',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $page = Livewire::test('pages::trova.index')
        ->set('code', 'X002')->call('choose', 'garage')->call('next')
        ->assertSee('Quartieri e frazioni')
        ->assertSee('Disegna zona')
        ->call('setZoneMethod', 'neighborhood')->assertSet('zoneMethod', 'neighborhood')
        ->set('zoneId', (string) $zoneId)->assertSee('Centro test')
        ->call('next')->assertSet('step', 'review')->assertSee('Centro test');

    expect($page->get('zoneMethod'))->toBe('neighborhood');
});

it('draws a zone like Trova, also where no neighbourhoods exist', function () {
    // X002 has no geographic zones: drawing must still be available
    $page = Livewire::test('pages::trova.index')
        ->set('code', 'X002')->call('choose', 'garage')->call('next')
        ->call('setZoneMethod', 'draw')->assertSet('zoneMethod', 'draw')
        ->assertSee('Chiudi zona')
        ->call('next')->assertSet('step', 'zone')->assertSee('Disegna almeno tre punti e chiudi la zona.')
        // Crossing edges (a bow tie) are refused: the zone stays to be completed
        ->call('setPolygon', [[7.54, 44.38], [7.56, 44.40], [7.56, 44.38], [7.54, 44.40]])
        ->assertSet('polygon', [])
        // «Chiudi zona» with a proper square around the demo centre
        ->call('setPolygon', [[7.535, 44.380], [7.560, 44.380], [7.560, 44.400], [7.535, 44.400]])
        ->assertSet('polygon', [[7.535, 44.38], [7.56, 44.38], [7.56, 44.4], [7.535, 44.4]])
        ->call('next')->assertSet('step', 'review')
        ->set('min', '10')->set('max', '30')->call('next')
        ->assertSet('step', 'results')->assertSet('error', null)
        ->assertSee('Zona disegnata');

    expect($page->get('total'))->toBeGreaterThan(0);

    // «Ridisegna» empties the applied zone
    $page->call('edit')->set('step', 'zone')->call('setPolygon', [])->assertSet('polygon', []);
});

it('searches business activities and refuses an unknown street', function () {
    $page = Livewire::test('pages::trova.index')
        ->set('code', 'X002')->call('choose', 'business')->call('next')
        ->set('address', 'Via Roma')->assertSet('zoneMethod', 'address')
        ->call('next')->assertSet('step', 'type')
        ->set('activity', 'shop')->set('min', '10')->set('max', '500')
        ->call('next')->assertSet('step', 'locations')
        ->call('next')->assertSet('step', 'results')->assertSet('error', null)
        ->assertSee('Negozio e vendita al dettaglio');

    expect($page->get('total'))->toBeGreaterThan(0);

    $page->call('edit')->set('step', 'zone')->set('address', 'Via Inesistente Xyz')
        ->set('step', 'review')->call('next')
        ->assertSee('Questo indirizzo non è presente nell’archivio del Comune selezionato.');
});

it('saves a result and repeats a recent search', function () {
    $page = Livewire::test('pages::trova.index')
        ->set('code', 'X002')->call('choose', 'garage')->call('next')
        ->call('setZoneMethod', 'all')->call('next')
        ->set('min', '10')->set('max', '30')->call('next');

    $page->call('toggleFavorite', 0);
    expect(TrovaFavorite::query()->where('user_id', $this->user->id)->count())->toBe(1);
    $page->call('toggleFavorite', 0);
    expect(TrovaFavorite::query()->count())->toBe(0);

    $entry = TrovaSearchHistoryEntry::query()->firstOrFail();
    Livewire::test('pages::trova.index')->call('repeat', $entry->id)
        ->assertSet('step', 'results')->assertSet('choice', 'garage')->assertSet('min', '10');
});

it('saves named searches permanently and keeps them private to their owner', function () {
    $page = Livewire::test('pages::trova.index')
        ->set('code', 'X002')->call('choose', 'garage')->call('next')
        ->call('setZoneMethod', 'all')->call('next')
        ->set('min', '10')->set('max', '30')->call('next')
        ->set('savedSearchName', 'Ricerca per Pina Fantozzi')->call('saveSearch')
        ->assertHasNoErrors('savedSearchName');

    $saved = TrovaSavedSearch::query()->where('user_id', $this->user->id)->sole();
    expect($saved->name)->toBe('Ricerca per Pina Fantozzi')
        ->and($saved->criteria['code'])->toBe('X002')
        ->and($saved->total)->toBeGreaterThan(0);

    $otherUser = User::factory()->create();
    $foreign = TrovaSavedSearch::query()->create([
        'user_id' => $otherUser->id, 'name' => 'Ricerca privata', 'criteria' => $saved->criteria,
        'total' => $saved->total, 'completed_at' => now(),
    ]);

    $page->call('repeatSavedSearch', $foreign->id)->assertSet('step', 'results');
    expect(TrovaSavedSearch::query()->find($foreign->id))->not->toBeNull();

    $page->call('removeSavedSearch', $foreign->id);
    expect(TrovaSavedSearch::query()->find($foreign->id))->not->toBeNull();

    $page->call('repeatSavedSearch', $saved->id)->assertSet('step', 'results')->assertSet('choice', 'garage');
});

it('sends Trova result parcels to the cadastral archive without creating portfolio properties', function () {
    $membership = AgencyMembership::factory()->admin()->create(['user_id' => $this->user->id]);

    $municipality = Municipality::query()->create(['cadastral_code' => 'Y777', 'name' => 'Comune Archivio Test']);
    $release = CatalogRelease::query()->create(['municipality_id' => $municipality->id, 'code' => 'Y777-TEST', 'label' => 'Catalogo test', 'status' => 'active']);
    MunicipalityCatalog::query()->create(['municipality_id' => $municipality->id, 'catalog_release_id' => $release->id]);
    $parcel = Parcel::query()->create(['municipality_id' => $municipality->id, 'cadastral_kind' => 'F', 'section' => '', 'sheet' => '1', 'number' => '12']);
    $unit = CadastralUnit::query()->create(['parcel_id' => $parcel->id, 'subalterno' => '1', 'legacy_key' => '["Y777","Fabbricati","","1","12","1"]']);
    CadastralUnitVersion::query()->create([
        'cadastral_unit_id' => $unit->id, 'catalog_release_id' => $release->id, 'status' => 'eligible',
        'category' => 'A/2', 'search_group' => \App\Trova\Categories::group('A/2'),
        'consistency' => 4, 'consistency_unit' => 'vani', 'address_raw' => 'VIA TEST n. 12 Piano 1',
    ]);

    $page = Livewire::test('pages::trova.index')
        ->set('code', 'Y777')->call('choose', 'homes')->call('next')
        ->call('setZoneMethod', 'all')->call('next')->set('housing', 'apartment')
        ->set('min', '3')->set('max', '5')->call('next')
        ->assertSet('step', 'results')->assertSet('error', null);

    expect($page->get('rows'))->toHaveCount(1);
    $page->call('addResultToCensus', $parcel->id)->assertDispatched('crm-notice');

    expect(DB::table('agency_unit_observations')->where('cadastral_unit_id', $unit->id)->exists())->toBeTrue()
        ->and(DB::table('properties')->where('agency_id', $membership->agency_id)->count())->toBe(0);
});

it('hides database errors behind a retry and repeats the same search', function () {
    DB::statement('ALTER TABLE building_parcel_links RENAME TO building_parcel_links_broken'); // only the search query reads it; undone with the test transaction

    $page = Livewire::test('pages::trova.index')
        ->set('code', 'X002')->call('choose', 'garage')->call('next')
        ->call('setZoneMethod', 'all')->call('next')
        ->set('min', '10')->set('max', '30')->call('next')
        ->assertSet('error', 'Ricerca momentaneamente non disponibile. Riprova tra poco.')
        ->assertSet('retry', true)
        ->assertSee('Riprova')
        ->assertDontSee('SQLSTATE');

    DB::statement('ALTER TABLE building_parcel_links_broken RENAME TO building_parcel_links');
    $page->call('retrySearch')->assertSet('error', null);
    expect($page->get('total'))->toBeGreaterThan(0);
});

it('offers a wider radius when nothing is found around the point', function () {
    Livewire::test('pages::trova.index')
        ->set('code', 'X002')->call('choose', 'garage')->call('next')
        ->call('setZoneMethod', 'map')->call('setPoint', 45.0, 8.0) // far from the demo
        ->call('next')->set('min', '10')->set('max', '30')->call('next')
        ->assertSet('total', 0)
        ->assertSee('Non abbiamo trovato risultati con questi criteri nei dati disponibili.')
        ->assertSee('Amplia il raggio a 1 km');
});

it('shows the home with the archive coverage', function () {
    auth()->logout();

    $this->get(route('home'))->assertOk()
        ->assertSee('Cerca immobili.')
        ->assertSee('Guarda come si presenta REKO.')
        ->assertSee('Copertura dell’archivio REKO', false)
        ->assertSee('Cuneo (demo OSM)')
        ->assertSee(route('trova'));
});
