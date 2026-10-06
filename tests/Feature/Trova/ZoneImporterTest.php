<?php

use App\Models\ImportRun;
use App\Models\Municipality;
use Illuminate\Support\Facades\DB;

/** GeoJSON square of side 0.01° starting at [$lng, $lat]. */
function square(float $lng, float $lat): array
{
    return ['type' => 'Polygon', 'coordinates' => [[[$lng, $lat], [$lng + 0.01, $lat], [$lng + 0.01, $lat + 0.01], [$lng, $lat + 0.01], [$lng, $lat]]]];
}

function zoneFile(array $features): string
{
    $path = tempnam(sys_get_temp_dir(), 'zones');
    file_put_contents($path, json_encode(['type' => 'FeatureCollection', 'features' => $features]));

    return $path;
}

function zone(string $id, string $name, array $geometry, string $code = 'T001'): array
{
    return ['type' => 'Feature', 'properties' => ['id' => $id, 'name' => $name, 'municipalityCode' => $code, 'sourceUrl' => 'https://example.test'], 'geometry' => $geometry];
}

beforeEach(function () {
    $this->municipality = Municipality::query()->create(['cadastral_code' => 'T001', 'name' => 'Comune Test']);
});

it('imports zones into geographic_zones and records the run', function () {
    $file = zoneFile([zone('T001-1', 'Centro', square(7.5, 44.3)), zone('T001-2', 'Nord', square(7.5, 44.31))]);

    $this->artisan('trova:import-zones', ['file' => $file])
        ->expectsOutputToContain('Zone lette 2, importate 2, scartate 0.')
        ->assertSuccessful();

    $zone = DB::selectOne("select name, kind, indicative, ST_GeometryType(boundary) as type, ST_SRID(boundary) as srid from geographic_zones where code = 'T001-1'");
    expect(DB::table('geographic_zones')->where('municipality_id', $this->municipality->id)->count())->toBe(2)
        ->and($zone->name)->toBe('Centro')
        ->and($zone->kind)->toBe('zona-di-ricerca')
        ->and($zone->indicative)->toBeTrue()
        ->and($zone->type)->toBe('ST_MultiPolygon')
        ->and($zone->srid)->toBe(4326);

    $run = ImportRun::query()->sole();
    expect($run->status)->toBe('done')
        ->and($run->municipality_id)->toBe($this->municipality->id)
        ->and($run->rows_read)->toBe(2)
        ->and($run->rows_imported)->toBe(2)
        ->and($run->sha256)->toBe(hash_file('sha256', $file));
});

it('updates the same zones when imported again', function () {
    $this->artisan('trova:import-zones', ['file' => zoneFile([zone('T001-1', 'Centro', square(7.5, 44.3))])])->assertSuccessful();
    $this->artisan('trova:import-zones', ['file' => zoneFile([zone('T001-1', 'Centro storico', square(7.6, 44.3))])])->assertSuccessful();

    expect(DB::table('geographic_zones')->count())->toBe(1)
        ->and(DB::table('geographic_zones')->value('name'))->toBe('Centro storico')
        ->and(ImportRun::query()->count())->toBe(2);
});

it('writes nothing on a dry run', function () {
    $this->artisan('trova:import-zones', ['file' => zoneFile([zone('T001-1', 'Centro', square(7.5, 44.3))]), '--dry-run' => true])
        ->expectsOutputToContain('Zone lette 1, importate 1, scartate 0.')
        ->expectsOutputToContain('nessuna modifica')
        ->assertSuccessful();

    expect(DB::table('geographic_zones')->count())->toBe(0)->and(ImportRun::query()->count())->toBe(0);
});

it('rejects bad features and reports them as issues', function () {
    $bowTie = ['type' => 'Polygon', 'coordinates' => [[[7.5, 44.3], [7.6, 44.4], [7.6, 44.3], [7.5, 44.4], [7.5, 44.3]]]];
    $file = zoneFile([
        zone('T001-1', 'Buona', square(7.5, 44.3)),
        zone('T001-1', 'Ripetuta', square(7.5, 44.4)),
        zone('T001-3', '', square(7.5, 44.5)),
        zone('T001-4', 'Punto', ['type' => 'Point', 'coordinates' => [7.5, 44.3]]),
        zone('T001-5', 'Farfalla', $bowTie),
    ]);

    $this->artisan('trova:import-zones', ['file' => $file])
        ->expectsOutputToContain('importate 2, scartate 3')
        ->assertSuccessful();

    $run = ImportRun::query()->sole();
    expect($run->rows_rejected)->toBe(3)
        ->and($run->issues()->pluck('code')->all())->toBe(['duplicate-zone', 'zone-without-identity', 'invalid-geometry'])
        ->and($run->issues()->first()->row_number)->toBe(2)
        ->and($run->issues()->first()->payload['name'])->toBe('Ripetuta')
        // il papillon viene reso valido da PostGIS, non scartato
        ->and(DB::table('geographic_zones')->where('code', 'T001-5')->exists())->toBeTrue();
});

it('clips zones to the Comune boundary and reports it', function () {
    DB::statement("UPDATE municipalities SET boundary = ST_Multi(ST_GeomFromText('POLYGON((7.5 44.3, 7.505 44.3, 7.505 44.31, 7.5 44.31, 7.5 44.3))', 4326)) WHERE id = ?", [$this->municipality->id]);

    $this->artisan('trova:import-zones', ['file' => zoneFile([
        zone('T001-1', 'A cavallo', square(7.5, 44.3)),
        zone('T001-2', 'Fuori', square(8.5, 45.3)),
    ])])->assertSuccessful();

    $inside = DB::selectOne("select ST_XMax(boundary) as xmax from geographic_zones where code = 'T001-1'");
    $issues = ImportRun::query()->sole()->issues()->orderBy('row_number')->get();

    expect($inside->xmax)->toBe(7.505)
        ->and($issues->pluck('code')->all())->toBe(['clipped-to-municipality', 'invalid-geometry'])
        ->and(DB::table('geographic_zones')->where('code', 'T001-2')->exists())->toBeFalse();
});

it('filters by code, redirects with --into and replaces on request', function () {
    Municipality::query()->create(['cadastral_code' => 'T002', 'name' => 'Altro Comune']);
    $file = zoneFile([zone('T001-1', 'Uno', square(7.5, 44.3)), zone('T002-1', 'Due', square(7.5, 44.4), 'T002')]);

    $this->artisan('trova:import-zones', ['file' => $file, '--code' => 'T002', '--into' => 'T001'])->assertSuccessful();
    expect(DB::table('geographic_zones')->where('municipality_id', $this->municipality->id)->pluck('name')->all())->toBe(['Due']);

    $this->artisan('trova:import-zones', ['file' => $file, '--code' => 'T001', '--replace' => true])->assertSuccessful();
    expect(DB::table('geographic_zones')->where('municipality_id', $this->municipality->id)->pluck('name')->all())->toBe(['Uno']);

    $this->artisan('trova:import-zones', ['file' => $file, '--into' => 'T001'])
        ->expectsOutputToContain('Il file contiene zone di più Comuni')
        ->assertFailed();
});

it('refuses unusable files and unknown Comuni', function (string $content, string $message) {
    $path = tempnam(sys_get_temp_dir(), 'zones');
    file_put_contents($path, $content);

    $this->artisan('trova:import-zones', ['file' => $path])->expectsOutputToContain($message)->assertFailed();
    expect(DB::table('geographic_zones')->count())->toBe(0);
})->with([
    'not json' => ['{non json', 'Il file non è un JSON valido.'],
    'not a collection' => ['{"type":"Feature"}', 'Il file deve essere un GeoJSON FeatureCollection.'],
    'empty' => ['{"type":"FeatureCollection","features":[]}', 'Nessuna zona da importare.'],
    'unknown comune' => [json_encode(['type' => 'FeatureCollection', 'features' => [zone('Z-1', 'Zona', square(7.5, 44.3), 'Z999')]]), 'Comune Z999 non presente nel database.'],
]);

it('refuses a missing file', function () {
    $this->artisan('trova:import-zones', ['file' => '/non/esiste.json'])->expectsOutputToContain('File non leggibile')->assertFailed();
});
