<?php

use App\Models\CatalogRelease;
use App\Models\ImportRun;
use App\Models\Municipality;
use App\Models\User;
use App\Trova\CatalogSearch;
use App\Trova\SearchException;
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

function pageParcels(): UploadedFile
{
    $feature = fn ($sheet, $parcel, $lng) => ['type' => 'Feature', 'properties' => ['FOGLIO' => $sheet, 'NUMERO' => $parcel],
        'geometry' => ['type' => 'Polygon', 'coordinates' => [[[$lng, 44.39], [$lng + 0.001, 44.39], [$lng + 0.001, 44.391], [$lng, 44.391], [$lng, 44.39]]]]];

    return UploadedFile::fake()->createWithContent('particelle.geojson', json_encode(['type' => 'FeatureCollection', 'features' => [
        $feature('1', '10', 7.55), $feature('1', '20', 7.551), $feature('2', '5', 7.552),
    ]]));
}

beforeEach(function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->create(['is_admin' => true]));
});

it('is reserved to platform admins', function () {
    auth()->logout();
    $this->get(route('admin.catalog.index'))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create(['is_admin' => false]))->get(route('admin.catalog.index'))->assertForbidden();

    $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('admin.catalog.index'))
        ->assertOk()->assertSee('Catalogo')->assertSee('Nessuna bozza.')->assertSee('Nessuna edizione.');
});

it('goes from a SISTER file to a published, searchable Comune', function () {
    $page = Livewire::test('pages::admin.catalog.index')
        ->set('code', 'd999')
        ->set('name', 'Comune di Prova')
        ->set('note', 'primo caricamento')
        ->set('file', pageSister(pageUnits()))
        ->call('importSister')
        ->assertHasNoErrors()
        ->assertSee('Import avviato')
        ->assertSee('Comune di Prova (D999)')
        ->assertSee('Completato')
        ->assertSee('0 / 3')                 // 3 parcels, none placed yet
        ->assertSee('Carica le sagome al passo 2');

    $municipality = Municipality::query()->where('cadastral_code', 'D999')->sole();
    $draft = CatalogRelease::query()->where('municipality_id', $municipality->id)->sole();
    expect($draft->status)->toBe('draft')->and($draft->notes)->toBe('primo caricamento')
        ->and(ImportRun::query()->sole()->status)->toBe('done');

    $page->set('geoReleaseId', $draft->id)
        ->set('geoFile', pageParcels())
        ->set('sheetProperty', 'FOGLIO')
        ->set('parcelProperty', 'NUMERO')
        ->call('importGeometry')
        ->assertHasNoErrors()
        ->assertSee('Caricamento delle sagome avviato')
        ->assertSee('3 / 3')
        ->assertSee('3 particelle collocate');

    expect(fn () => (new CatalogSearch)->search(['code' => 'D999', 'segment' => 'private', 'min' => 3, 'max' => 5]))->toThrow(SearchException::class);

    $page->call('publish', $draft->id)->assertSee('Edizione pubblicata')->assertSee('Pubblicata')->assertSee('Nessuna bozza.');

    expect((new CatalogSearch)->search(['code' => 'D999', 'segment' => 'private', 'min' => 3, 'max' => 5, 'circle' => ['lat' => 44.3905, 'lng' => 7.5515, 'radius' => 500]])->total)->toBe(2);   // 2 parcels: total counts parcels, not units
});

it('shows what changed in a new edition and lets the admin discard it', function () {
    $page = Livewire::test('pages::admin.catalog.index')
        ->set('code', 'D999')->set('name', 'Comune di Prova')->set('file', pageSister(pageUnits()))->call('importSister');
    $first = CatalogRelease::query()->sole();
    $page->call('publish', $first->id);

    $changed = pageUnits();
    $changed[1][5] = '6 vani';
    $changed[] = ['3', '8', '1', 'VIA NUOVA n. 2 Piano T', 'A02', '3 vani'];

    $page->set('code', 'D999')->set('file', pageSister($changed))->call('importSister')
        ->assertHasNoErrors()
        ->assertSee('Rispetto all’edizione '.$first->code)
        ->assertSee('nuove')
        ->assertSee('variate');

    $draft = CatalogRelease::query()->where('status', 'draft')->sole();
    expect(ImportRun::query()->where('catalog_release_id', $draft->id)->value('summary')['diff'])->toEqual(['added' => 1, 'changed' => 1, 'unchanged' => 3, 'removed' => 0]);

    $page->call('discard', $draft->id)->assertSee('Bozza scartata');
    expect(CatalogRelease::query()->count())->toBe(1)->and($first->fresh()->status)->toBe('active');
});

it('tells the admin what is wrong with the forms', function () {
    Livewire::test('pages::admin.catalog.index')
        ->call('importSister')
        ->assertHasErrors(['code', 'file'])
        ->assertSee('Indica il codice catastale del Comune.')
        ->assertSee('Carica il file SISTER.')
        ->set('code', 'D20')->set('file', pageSister(pageUnits()))->call('importSister')
        ->assertSee('Il codice catastale ha una lettera e tre cifre, per esempio D205.')
        ->set('code', 'D999')->call('importSister')
        ->assertSee('Il Comune D999 non esiste ancora: indica anche il nome.')
        ->set('file', UploadedFile::fake()->createWithContent('export.pdf', 'x'))->call('importSister')
        ->assertSee('Il file deve essere un .txt, .csv o .tsv.')
        ->call('importGeometry')
        ->assertSee('Scegli la bozza a cui appartengono le sagome.')
        ->assertSee('Carica il file GeoJSON.');

    expect(Municipality::query()->count())->toBe(0)->and(CatalogRelease::query()->count())->toBe(0);
});

it('refuses a second draft for the same Comune and half a property mapping', function () {
    $page = Livewire::test('pages::admin.catalog.index')
        ->set('code', 'D999')->set('name', 'Comune di Prova')->set('file', pageSister(pageUnits()))->call('importSister')
        ->set('code', 'D999')->set('file', pageSister(pageUnits()))->call('importSister')
        ->assertSee('ha già una bozza');

    $draft = CatalogRelease::query()->sole();
    $page->set('geoReleaseId', $draft->id)->set('geoFile', pageParcels())->set('sheetProperty', 'FOGLIO')->call('importGeometry')
        ->assertSee('Indica sia la proprietà del foglio sia quella della particella');

    expect(DB::table('parcel_search_points')->count())->toBe(0);
});

it('reports a file with no SISTER rows as failed and lets the admin throw the draft away', function () {
    $page = Livewire::test('pages::admin.catalog.index')
        ->set('code', 'D999')->set('name', 'Vuoto')
        ->set('file', UploadedFile::fake()->createWithContent('vuoto.txt', "SISTER\nniente di utile\n"))
        ->call('importSister')
        ->assertSee('Fallito')
        ->assertSee('Nessuna unità riconosciuta');

    $page->call('publish', CatalogRelease::query()->sole()->id)->assertSee('La bozza non è pronta');
    $page->call('discard', CatalogRelease::query()->sole()->id);

    expect(CatalogRelease::query()->count())->toBe(0)->and(Municipality::query()->count())->toBe(0);
});
