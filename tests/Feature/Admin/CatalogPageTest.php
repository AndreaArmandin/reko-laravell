<?php

use App\Models\CatalogRelease;
use App\Models\Municipality;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

function pageSister(array $units): UploadedFile
{
    $lines = ['SISTER - Visura fabbricati'];
    foreach ($units as [$sheet, $parcel, $sub, $address, $category, $consistency]) {
        $lines[] = implode("\t", [$sheet, $parcel, $sub, $address, '001', $category, '02', $consistency, 'R.Euro:300,00', '', '']);
    }

    return UploadedFile::fake()->createWithContent('export-sister.txt', implode("\n", $lines)."\n");
}

function pageUnits(): array
{
    return [
        ['1', '10', '1', 'VIA ROMA n. 1 Piano T', 'A02', '4 vani'],
        ['1', '10', '2', 'VIA ROMA n. 1 Piano 1', 'A02', '5 vani'],
        ['1', '20', '1', 'VIA MILANO n. 4 Piano 1', 'A03', '3 vani'],
        ['2', '5', '1', 'CORSO ITALIA n. 7 Piano T', 'C01', '120 m²'],
    ];
}

function pageGeo(string $name, array $features): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, json_encode(['type' => 'FeatureCollection', 'features' => $features]));
}

function pageSquare(float $lng, float $size = 0.001): array
{
    return ['type' => 'Polygon', 'coordinates' => [[[$lng, 44.39], [$lng + $size, 44.39], [$lng + $size, 44.39 + $size], [$lng, 44.39 + $size], [$lng, 44.39]]]];
}

beforeEach(function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->create(['is_admin' => true]));
});

it('is reserved to platform admins and lists the Comuni', function () {
    Municipality::query()->create(['cadastral_code' => 'D998', 'name' => 'Altro Comune']);
    auth()->logout();
    $this->get(route('admin.catalog.index'))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create(['is_admin' => false]))->get(route('admin.catalog.index'))->assertForbidden();

    $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('admin.catalog.index'))->assertOk()->assertSee('Altro Comune');
    Livewire::test('pages::admin.catalog.index')->set('search', 'zzz')->assertDontSee('Altro Comune')->set('search', 'D998')->assertSee('Altro Comune');
});

it('adds a Comune and walks the wizard: SISTER, parcels, buildings, publish, new edition, restore', function () {
    Livewire::test('pages::admin.catalog.index')->set('adding', true)->set('name', 'Comune di Prova')->set('code', 'd999')
        ->call('addMunicipality')->assertHasNoErrors()->assertRedirect();
    $m = Municipality::query()->where('cadastral_code', 'D999')->sole();

    $page = Livewire::test('pages::admin.catalog.show', ['municipality' => $m])
        ->set('file', pageSister(pageUnits()))->call('importSister')->assertHasNoErrors()->assertSee('0 / 3');   // SISTER done: the wizard moves on to the parcels

    // parcels: fields detected from the file, sample checked before importing
    $parcels = pageGeo('particelle.geojson', [
        ['type' => 'Feature', 'properties' => ['FOGLIO' => '1', 'NUMERO' => '10'], 'geometry' => pageSquare(7.55)],
        ['type' => 'Feature', 'properties' => ['FOGLIO' => '1', 'NUMERO' => '20'], 'geometry' => pageSquare(7.552)],
        ['type' => 'Feature', 'properties' => ['FOGLIO' => '2', 'NUMERO' => '5'], 'geometry' => pageSquare(7.554)],
    ]);
    // a file without foglio/particella is refused with a plain message
    $page->call('goTo', 'parcels')->set('geoFile', pageGeo('strade.geojson', [['type' => 'Feature', 'properties' => ['NOME' => 'Via Roma'], 'geometry' => pageSquare(7.55)]]))
        ->assertSee('Nel file non trovo foglio e particella')->call('importGeometry')->assertHasErrors('geoFile');
    $page->set('geoFile', $parcels)
        ->assertSet('check.map.sheet', 'FOGLIO')->assertSet('check.map.parcel', 'NUMERO')->assertSet('check.epsg', 6706)
        ->assertSee('File riconosciuto: foglio e particella trovati · 3/3 sagome del campione abbinate')
        ->call('importGeometry')->assertHasNoErrors()->assertSee('3 particelle collocate');

    // buildings: no parcel fields, linked by position
    $page->call('goTo', 'buildings')->set('geoFile', pageGeo('fabbricati.geojson', [
        ['type' => 'Feature', 'properties' => ['ID' => 'B1'], 'geometry' => pageSquare(7.5502, 0.0005)],
        ['type' => 'Feature', 'properties' => ['ID' => 'B2'], 'geometry' => pageSquare(7.70, 0.0005)],   // on no parcel
    ]))->assertSee('per posizione')->call('importGeometry')->assertHasNoErrors()->assertSee('1 fabbricati collegati');
    $draft = CatalogRelease::query()->where('municipality_id', $m->id)->sole();
    expect(DB::table('building_versions')->where('catalog_release_id', $draft->id)->count())->toBe(1)
        ->and(DB::table('building_parcel_links')->where('catalog_release_id', $draft->id)->count())->toBe(1);

    // outlines outside Italy are caught on the sample
    $page->set('geoFile', pageGeo('parigi.geojson', [['type' => 'Feature', 'properties' => [], 'geometry' => pageSquare(2.35)]]))
        ->assertSee('non cadono in Italia');

    $page->call('goTo', 'summary')->call('publish')->assertSee('Edizione pubblicata');
    expect($draft->fresh()->status)->toBe('active');

    // new edition with half the units: publishing asks for a confirmation; buildings carried over
    $page->set('newEdition', true)->set('file', pageSister(array_slice(pageUnits(), 0, 1)))->call('importSister')
        ->call('goTo', 'summary')->assertSee('unità in meno')->call('publish')->assertHasErrors('publish')
        ->set('confirmRisk', true)->call('publish')->assertHasNoErrors();
    expect($draft->fresh()->status)->toBe('archived');

    $page->call('restore', $draft->id)->assertSee('Edizione ripristinata');
    expect($draft->fresh()->status)->toBe('active')
        ->and(DB::table('municipality_catalogs')->where('municipality_id', $m->id)->value('catalog_release_id'))->toBe($draft->id);
});

it('imports the dummy Torino parcels file with an enabled Importa button', function () {
    $dir = getenv('HOME').'/Downloads/reko-dummy-torino';
    if (! is_file("$dir/particelle-torino-dummy.geojson")) {
        $this->markTestSkipped('dummy Torino non presente');
    }
    $m = Municipality::query()->create(['cadastral_code' => 'L219', 'name' => 'Torino']);
    $page = Livewire::test('pages::admin.catalog.show', ['municipality' => $m])
        ->set('file', UploadedFile::fake()->createWithContent('sister.txt', file_get_contents("$dir/sister-torino-dummy.txt")))->call('importSister')->assertHasNoErrors()
        ->call('goTo', 'parcels')
        ->set('geoFile', UploadedFile::fake()->createWithContent('particelle.geojson', file_get_contents("$dir/particelle-torino-dummy.geojson")))
        ->assertSee('13/13');
    $page->call('importGeometry')->assertHasNoErrors()->assertSee('13 particelle collocate');
});
