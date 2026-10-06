<?php

use App\Models\ImportRun;
use App\Models\Municipality;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

function adminSquare(float $lng, float $lat): array
{
    return ['type' => 'Polygon', 'coordinates' => [[[$lng, $lat], [$lng + 0.01, $lat], [$lng + 0.01, $lat + 0.01], [$lng, $lat + 0.01], [$lng, $lat]]]];
}

/** A file from another source: no municipalityCode, no id, the name lives in NOME. */
function foreignFile(string $name = 'quartieri.geojson'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, json_encode(['type' => 'FeatureCollection', 'features' => [
        ['type' => 'Feature', 'properties' => ['NOME' => 'Centro'], 'geometry' => adminSquare(7.5, 44.3)],
        ['type' => 'Feature', 'properties' => ['NOME' => 'Nord'], 'geometry' => adminSquare(7.5, 44.31)],
        ['type' => 'Feature', 'properties' => ['NOME' => ''], 'geometry' => adminSquare(7.5, 44.32)],
    ]]));
}

beforeEach(function () {
    $this->municipality = Municipality::query()->create(['cadastral_code' => 'T001', 'name' => 'Comune Test']);
});

it('is reserved to platform admins', function () {
    $this->get(route('admin.zones.index'))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create(['is_admin' => false]))->get(route('admin.zones.index'))->assertForbidden();

    $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('admin.zones.index'))
        ->assertOk()->assertSee('Zone di ricerca')->assertSee('Nessun import ancora.');
});

it('checks the file without writing anything, then imports it on request', function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->create(['is_admin' => true]));

    $page = Livewire::test('pages::admin.zones.index')
        ->set('municipalityId', $this->municipality->id)
        ->set('nameProperty', 'NOME')
        ->set('file', foreignFile())
        ->call('check')
        ->assertHasNoErrors()
        ->assertSee('Risultato del controllo')
        ->assertSee('Importa 2 zone')
        ->assertSee('La zona non ha un nome.')
        ->assertSeeHtml('aria-label="Anteprima delle zone"');

    expect($page->get('checked'))->toMatchArray(['read' => 3, 'imported' => 2, 'rejected' => 1])
        ->and(DB::table('geographic_zones')->count())->toBe(0)
        ->and(ImportRun::query()->count())->toBe(0);

    $page->call('import')->assertSee('Importate 2 zone, scartate 1.')->assertSet('checked', null);

    $run = ImportRun::query()->sole();
    expect(DB::table('geographic_zones')->where('municipality_id', $this->municipality->id)->orderBy('code')->pluck('name', 'code')->all())
        ->toBe(['T001-01' => 'Centro', 'T001-02' => 'Nord'])
        ->and($run->original_filename)->toBe('quartieri.geojson')
        ->and($run->status)->toBe('done');
    Storage::disk('local')->assertExists($run->private_path);
});

it('invalidates the check when an option changes', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));

    Livewire::test('pages::admin.zones.index')
        ->set('municipalityId', $this->municipality->id)
        ->set('nameProperty', 'NOME')
        ->set('file', foreignFile())
        ->call('check')
        ->assertSet('checked.imported', 2)
        ->set('nameProperty', 'name')
        ->assertSet('checked', null)
        ->call('import');

    expect(DB::table('geographic_zones')->count())->toBe(0);
});

it('explains what is wrong with the form or the file', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));

    Livewire::test('pages::admin.zones.index')
        ->call('check')
        ->assertHasErrors(['municipalityId', 'file'])
        ->assertSee('Scegli il Comune a cui appartengono le zone.')
        ->assertSee('Carica un file GeoJSON.')
        ->set('municipalityId', $this->municipality->id)
        ->set('file', UploadedFile::fake()->createWithContent('zone.txt', '{}'))
        ->call('check')
        ->assertSee('Il file deve essere un .geojson o un .json.')
        ->set('file', UploadedFile::fake()->createWithContent('zone.geojson', '{non json'))
        ->call('check')
        ->assertSee('Il file non è un JSON valido.')
        ->set('file', foreignFile())
        ->call('check')
        ->assertSee('Nessuna zona importabile')
        ->assertDontSee('Importa 2 zone');

    expect(DB::table('geographic_zones')->count())->toBe(0);
});
