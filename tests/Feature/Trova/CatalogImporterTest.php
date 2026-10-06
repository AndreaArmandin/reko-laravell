<?php

use App\Models\CatalogRelease;
use App\Models\ImportRun;
use App\Models\Municipality;
use App\Trova\CatalogImporter;
use App\Trova\CatalogSearch;
use App\Trova\ParcelGeometryImporter;
use App\Trova\SearchException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** One SISTER row: foglio, particella, sub, indirizzo, zona, categoria, classe, consistenza, rendita. */
function catRow(string $sheet, string $parcel, string $sub, string $address, string $category = 'A02', string $consistency = '4 vani', string $extra = ''): string
{
    return implode("\t", [$sheet, $parcel, $sub, $address, '001', $category, '02', $consistency, 'R.Euro:300,00', '', $extra]);
}

function catFile(array $lines, string $name = 'sister.txt'): string
{
    Storage::disk('local')->put("catalog-imports/{$name}", implode("\n", $lines)."\n");

    return "catalog-imports/{$name}";
}

/** A small Comune: 3 parcels, 6 units, plus one suppressed, one common good, one broken and one repeated row. */
function catBase(): array
{
    return [
        'SISTER - Visura fabbricati',
        'Comune: TESTA',
        "Foglio\tParticella\tSub\tIndirizzo",
        catRow('1', '10', '1', 'VIA ROMA n. 1 Piano T', 'A02', '4 vani'),
        catRow('1', '10', '2', 'VIA ROMA n. 1 Piano 1', 'A02', '5 vani'),
        catRow('1', '10', '3', 'VIA ROMA n. 1 Piano T', 'C06', '18 m²'),
        catRow('1', '20', '1', 'VIA MILANO n. 4 Piano 1', 'A03', '3 vani'),
        catRow('1', '20', '2', 'VIA MILANO n. 4 Piano 2', 'A10', '6,5 vani'),
        catRow('2', '5', '1', 'CORSO ITALIA n. 7 Piano T', 'C01', '120 m²'),
        catRow('2', '5', '2', 'CORSO ITALIA n. 7 Piano 1', 'A02', '4 vani', 'Unità soppressa'),
        catRow('2', '5', '3', 'CORSO ITALIA n. 7 Piano 1', 'A02', '4 vani').' Bene comune non censibile',
        catRow('1', '10', '1', 'VIA ROMA n. 1 Piano T', 'A02', '4 vani'),
        "1\t2\t3\tVIA ROTTA",
        'riga che non è un dato',
    ];
}

function catGeometry(string $sheet, string $parcel, float $lng, float $lat): array
{
    return ['type' => 'Feature', 'properties' => ['FOGLIO' => $sheet, 'PARTICELLA' => $parcel], 'geometry' => ['type' => 'Polygon', 'coordinates' => [[[$lng, $lat], [$lng + 0.001, $lat], [$lng + 0.001, $lat + 0.001], [$lng, $lat + 0.001], [$lng, $lat]]]]];
}

function catGeoFile(array $features, string $name = 'parcels.geojson'): string
{
    Storage::disk('local')->put("catalog-imports/{$name}", json_encode(['type' => 'FeatureCollection', 'features' => $features]));

    return "catalog-imports/{$name}";
}

function catImport(string $code, string $path, string $name = 'Comune Test A'): ImportRun
{
    $importer = app(CatalogImporter::class);

    return $importer->process($importer->prepare($code, $name, $path, basename($path)));
}

function catDraft(string $code): CatalogRelease
{
    return CatalogRelease::query()->where('status', 'draft')->whereHas('municipality', fn ($q) => $q->where('cadastral_code', $code))->firstOrFail();
}

beforeEach(fn () => Storage::fake('local'));

it('creates the Comune and a draft edition from a SISTER file, without publishing it', function () {
    $run = catImport('T001', catFile(catBase()));

    $municipality = Municipality::query()->where('cadastral_code', 'T001')->sole();
    $release = catDraft('T001');

    expect($run->status)->toBe('done')
        ->and($municipality->name)->toBe('Comune Test A')
        ->and($release->status)->toBe('draft')
        ->and($release->code)->toBe('T001-'.now()->format('Ymd'))
        ->and($run->rows_read)->toBe(11)          // 3 header lines are not data
        ->and($run->rows_imported)->toBe(8)       // 8 distinct units
        ->and($run->rows_rejected)->toBe(2)       // broken row + a line that is not data
        ->and($run->summary['units']['total'])->toBe(8)
        ->and($run->summary['units']['eligible'])->toBe(6)
        ->and($run->summary['repeated'])->toBe(1)
        ->and($run->summary['diff'])->toBeNull()
        ->and(DB::table('municipality_catalogs')->where('municipality_id', $municipality->id)->exists())->toBeFalse();

    expect(DB::table('parcels')->where('municipality_id', $municipality->id)->count())->toBe(3)
        ->and(DB::table('parcels')->where('municipality_id', $municipality->id)->where('cadastral_kind', 'F')->count())->toBe(3);

    $unit = DB::selectOne("SELECT v.*, u.legacy_key, u.subalterno FROM cadastral_unit_versions v JOIN cadastral_units u ON u.id = v.cadastral_unit_id
        JOIN parcels p ON p.id = u.parcel_id WHERE v.catalog_release_id = ? AND p.number = '20' AND u.subalterno = '2'", [$release->id]);
    expect($unit->category)->toBe('A/10')
        ->and($unit->search_group)->toBe('A10')
        ->and((float) $unit->consistency)->toBe(6.5)
        ->and($unit->consistency_unit)->toBe('vani')
        ->and((float) $unit->rendita)->toBe(300.0)
        ->and($unit->address_toponym)->toBe('VIA MILANO')
        ->and($unit->address_number)->toBe('4')
        ->and($unit->floor)->toBe('2')
        ->and($unit->census_zone)->toBe('001')
        ->and($unit->status)->toBe('eligible')
        ->and($unit->legacy_key)->toBe('["T001","Fabbricati","","1","20","2"]');

    $statuses = DB::table('cadastral_unit_versions')->where('catalog_release_id', $release->id)->where('status', 'excluded')->pluck('status_reason')->sort()->values()->all();
    expect($statuses)->toBe(['Bene comune non censibile', 'Soppressione presente nelle fonti']);

    // the rejected lines are explained
    expect($run->issues()->pluck('code')->sort()->values()->all())->toBe(['repeated-rows', 'row-malformed', 'row-unrecognized']);

    // trova_unit_facts is built for the draft too
    expect(DB::table('trova_unit_facts')->where('catalog_release_id', $release->id)->count())->toBe(6);

    // users still see nothing
    expect(fn () => (new CatalogSearch)->search(['code' => 'T001', 'segment' => 'private', 'min' => 1, 'max' => 10]))
        ->toThrow(SearchException::class, 'Archivio del Comune in preparazione');
});

it('places parcels from a GeoJSON file, by mapped properties or by national reference', function () {
    catImport('T001', catFile(catBase()));
    $release = catDraft('T001');
    $geometry = app(ParcelGeometryImporter::class);

    $path = catGeoFile([
        catGeometry('1', '10', 7.55, 44.39),
        catGeometry('0001', '0020', 7.56, 44.39),                  // leading zeros
        catGeometry('9', '99', 7.57, 44.39),                       // no units in the draft
        ['type' => 'Feature', 'properties' => [], 'geometry' => ['type' => 'Polygon', 'coordinates' => [[[7, 44], [7.1, 44], [7.1, 44.1], [7, 44]]]]], // no reference
    ]);
    $run = $geometry->process($geometry->prepare($release, $path, 'parcels.geojson', ['sheet' => 'FOGLIO', 'parcel' => 'PARTICELLA']));

    expect($run->status)->toBe('done')
        ->and($run->rows_read)->toBe(4)
        ->and($run->rows_imported)->toBe(2)
        ->and($run->rows_rejected)->toBe(1)
        ->and($run->summary['coverage'])->toEqual(['parcels' => 3, 'located' => 2])
        ->and($run->summary['not_in_edition'])->toBe(1)
        ->and($run->issues()->pluck('code')->sort()->values()->all())->toBe(['feature-rejected', 'parcel-not-in-edition']);

    $point = DB::selectOne("SELECT ST_X(s.location) lng, s.source FROM parcel_search_points s JOIN parcels p ON p.id = s.parcel_id WHERE s.catalog_release_id = ? AND p.number = '10'", [$release->id]);
    expect(round($point->lng, 4))->toBe(7.5505)->and($point->source)->toBe('cadastral-map')
        ->and(DB::table('parcel_versions')->where('catalog_release_id', $release->id)->whereNotNull('boundary')->count())->toBe(2);

    // the national cadastral reference of the Agenzia delle Entrate cartography
    $national = catGeoFile([['type' => 'Feature', 'properties' => ['NATIONALCADASTALREFERENCE' => 'T001_000200.5'], 'geometry' => catGeometry('', '', 7.58, 44.39)['geometry']],
        ['type' => 'Feature', 'properties' => ['NATIONALCADASTALREFERENCE' => 'Z999_000200.5'], 'geometry' => catGeometry('', '', 7.59, 44.39)['geometry']]], 'national.geojson');
    $run = $geometry->process($geometry->prepare($release, $national, 'national.geojson'));

    expect($run->rows_imported)->toBe(1)->and($run->summary['coverage'])->toEqual(['parcels' => 3, 'located' => 3])
        ->and($run->issues()->where('code', 'feature-rejected')->value('message'))->toContain('di un altro Comune');
});

it('refuses geometry without a draft or with unreadable files', function () {
    catImport('T001', catFile(catBase()));
    $release = catDraft('T001');
    $geometry = app(ParcelGeometryImporter::class);

    $bad = Storage::disk('local')->put('catalog-imports/bad.geojson', '{non json');
    $run = $geometry->process($geometry->prepare($release, 'catalog-imports/bad.geojson', 'bad.geojson'));
    expect($run->status)->toBe('failed')->and($run->issues()->first()->message)->toBe('Il file non è un JSON valido.');

    $empty = catGeoFile([catGeometry('77', '77', 7.5, 44.3)], 'nomatch.geojson');
    $run = $geometry->process($geometry->prepare($release, $empty, 'nomatch.geojson', ['sheet' => 'FOGLIO', 'parcel' => 'PARTICELLA']));
    expect($run->status)->toBe('failed')->and($run->issues()->pluck('code')->all())->toContain('no-match');

    app(CatalogImporter::class)->discard($release);
    expect(fn () => $geometry->prepare($release->fresh() ?? $release, $empty, 'x'))->toThrow(RuntimeException::class);
});

it('publishes the draft: the Comune becomes searchable and the old edition is archived', function () {
    $importer = app(CatalogImporter::class);
    catImport('T001', catFile(catBase()));
    $first = catDraft('T001');
    $geometry = app(ParcelGeometryImporter::class);
    $geometry->process($geometry->prepare($first, catGeoFile([catGeometry('1', '10', 7.55, 44.39), catGeometry('1', '20', 7.551, 44.39), catGeometry('2', '5', 7.552, 44.39)]), 'p.geojson', ['sheet' => 'FOGLIO', 'parcel' => 'PARTICELLA']));

    $importer->publish($first);

    $result = (new CatalogSearch)->search(['code' => 'T001', 'segment' => 'private', 'min' => 3, 'max' => 5, 'circle' => ['lat' => 44.3905, 'lng' => 7.5515, 'radius' => 500]]);
    // A/2 4 vani, A/3 3 vani, A/2 5 vani qualify; the 6.5 vani A/10 is not a home; the excluded A/2 are hidden
    expect($result->total)->toBe(2)
        ->and($first->fresh()->status)->toBe('active')
        ->and(DB::table('municipality_catalogs')->where('municipality_id', $first->municipality_id)->value('catalog_release_id'))->toBe($first->id)
        ->and(fn () => $importer->publish($first))->toThrow(RuntimeException::class, 'Solo una bozza si può pubblicare.');
});

it('builds the next edition from a complete file: geometry carried over, changes counted', function () {
    $importer = app(CatalogImporter::class);
    catImport('T001', catFile(catBase()));
    $first = catDraft('T001');
    $geometry = app(ParcelGeometryImporter::class);
    $geometry->process($geometry->prepare($first, catGeoFile([catGeometry('1', '10', 7.55, 44.39), catGeometry('1', '20', 7.551, 44.39), catGeometry('2', '5', 7.552, 44.39)]), 'p.geojson', ['sheet' => 'FOGLIO', 'parcel' => 'PARTICELLA']));
    $importer->publish($first);

    // a second draft cannot start while one exists, and the file must be a new one
    $second = [
        catRow('1', '10', '1', 'VIA ROMA n. 1 Piano T', 'A02', '4 vani'),                  // unchanged
        catRow('1', '10', '2', 'VIA ROMA n. 1 Piano 1', 'A02', '6 vani'),                  // changed consistency
        // sub 3 of parcel 10 is gone
        catRow('1', '20', '1', 'VIA MILANO n. 4 Piano 1', 'A03', '3 vani'),                // unchanged
        catRow('1', '20', '2', 'VIA MILANO n. 4 Piano 2', 'A10', '6,5 vani'),              // unchanged
        catRow('2', '5', '1', 'CORSO ITALIA n. 7 Piano T', 'C01', '120 m²'),               // unchanged
        catRow('3', '8', '1', 'VIA NUOVA n. 2 Piano T', 'A02', '3 vani'),                  // new parcel and unit
    ];
    $run = catImport('T001', catFile($second, 'second.txt'));
    $draft = catDraft('T001');

    expect($run->status)->toBe('done')
        ->and($draft->id)->not->toBe($first->id)
        ->and($run->summary['previous_release'])->toBe($first->code)
        ->and($run->summary['diff'])->toEqual(['added' => 1, 'changed' => 1, 'unchanged' => 4, 'removed' => 3])
        ->and(CatalogImporter::coverage($draft->id))->toEqual(['parcels' => 4, 'located' => 3])   // positions carried over, new parcel waits
        ->and(DB::table('building_parcel_links')->count())->toBe(0);

    // users still search the published edition until the draft is published
    expect((new CatalogSearch)->search(['code' => 'T001', 'segment' => 'private', 'min' => 3, 'max' => 5, 'circle' => ['lat' => 44.3905, 'lng' => 7.5515, 'radius' => 500]])->total)->toBe(2);

    expect(fn () => app(CatalogImporter::class)->prepare('T001', null, catFile($second, 'third.txt'), 'third.txt'))
        ->toThrow(RuntimeException::class, 'ha già una bozza');

    $importer->publish($draft);
    expect($first->fresh()->status)->toBe('archived')->and($draft->fresh()->status)->toBe('active')
        ->and(DB::table('municipality_catalogs')->where('municipality_id', $draft->municipality_id)->value('catalog_release_id'))->toBe($draft->id)
        // the same units keep their identity across editions
        ->and(DB::table('cadastral_units')->count())->toBe(8 + 1);
});

it('discards a draft with what it loaded, and a Comune that only existed for it', function () {
    catImport('T001', catFile(catBase()));
    expect(Municipality::query()->where('cadastral_code', 'T001')->exists())->toBeTrue();

    app(CatalogImporter::class)->discard(catDraft('T001'));

    expect(CatalogRelease::query()->count())->toBe(0)
        ->and(Municipality::query()->where('cadastral_code', 'T001')->exists())->toBeFalse()
        ->and(DB::table('parcels')->count())->toBe(0)
        ->and(DB::table('cadastral_units')->count())->toBe(0)
        ->and(DB::table('cadastral_unit_versions')->count())->toBe(0)
        ->and(ImportRun::query()->where('source_name', 'sister-catalog')->value('status'))->toBe('discarded');
});

it('keeps an existing Comune and its published edition when a draft is discarded', function () {
    $importer = app(CatalogImporter::class);
    catImport('T001', catFile(catBase()));
    $first = catDraft('T001');
    $importer->publish($first);
    catImport('T001', catFile([catRow('1', '10', '1', 'VIA ROMA n. 1 Piano T')], 'second.txt'));

    $importer->discard(catDraft('T001'));

    expect($first->fresh()->status)->toBe('active')
        ->and(Municipality::query()->where('cadastral_code', 'T001')->exists())->toBeTrue()
        ->and(DB::table('cadastral_unit_versions')->where('catalog_release_id', $first->id)->count())->toBe(8)
        ->and(DB::table('cadastral_units')->count())->toBe(8);
});

it('refuses what it cannot import and says why', function () {
    $importer = app(CatalogImporter::class);
    $file = catFile(catBase());

    expect(fn () => $importer->prepare('D20', 'Cuneo', $file, 'x.txt'))->toThrow(RuntimeException::class, 'una lettera e tre cifre')
        ->and(fn () => $importer->prepare('d205', '', $file, 'x.txt'))->toThrow(RuntimeException::class, 'indica anche il nome')
        ->and(fn () => $importer->prepare('D205', 'Cuneo', 'catalog-imports/missing.txt', 'x.txt'))->toThrow(RuntimeException::class, 'File non trovato.')
        ->and(Municipality::query()->count())->toBe(0);

    $run = catImport('T002', catFile(['SISTER', 'Comune: VUOTO', 'niente di utile'], 'empty.txt'), 'Vuoto');
    expect($run->status)->toBe('failed')
        ->and($run->issues()->pluck('code')->all())->toContain('no-units');

    expect(fn () => $importer->publish(catDraft('T002')))->toThrow(RuntimeException::class, 'La bozza non è pronta');
});

it('lowercase codes and an existing Comune do not need a name', function () {
    catImport('t001', catFile(catBase()));
    app(CatalogImporter::class)->discard(catDraft('T001'));   // Comune goes away with its draft

    $municipality = Municipality::query()->create(['cadastral_code' => 'T003', 'name' => 'Terzo']);
    $run = app(CatalogImporter::class)->process(app(CatalogImporter::class)->prepare('t003', null, catFile(catBase(), 'terzo.txt'), 'terzo.txt'));

    expect($run->status)->toBe('done')->and($run->municipality_id)->toBe($municipality->id)->and($municipality->fresh()->name)->toBe('Terzo');
});

it('handles repeated and later-suppressed rows far apart, across chunk boundaries', function () {
    $lines = [];
    foreach (range(1, 2300) as $i) {
        $lines[] = catRow('1', (string) $i, '1', "VIA LUNGA n. {$i} Piano T", 'A02', '4 vani');
    }
    $lines[] = catRow('1', '5', '1', 'VIA LUNGA n. 5 Piano T', 'A02', '9 vani');                  // repeated, different data: first one stays
    $lines[] = catRow('1', '7', '1', 'VIA LUNGA n. 7 Piano T', 'A02', '4 vani').' soppresso';       // suppression arrives 2000+ rows later
    $lines[] = catRow('1', '2200', '1', 'VIA LUNGA n. 2200 Piano T', 'A02', '4 vani').' soppresso';

    $run = catImport('T001', catFile($lines, 'long.txt'));
    $release = catDraft('T001');
    $version = fn (string $number) => DB::selectOne('SELECT v.status, v.consistency FROM cadastral_unit_versions v JOIN cadastral_units u ON u.id = v.cadastral_unit_id
        JOIN parcels p ON p.id = u.parcel_id WHERE v.catalog_release_id = ? AND p.number = ?', [$release->id, $number]);

    expect($run->status)->toBe('done')
        ->and($run->rows_imported)->toBe(2300)
        ->and($run->summary['repeated'])->toBe(3)
        ->and(DB::table('cadastral_unit_versions')->where('catalog_release_id', $release->id)->count())->toBe(2300)
        ->and((float) $version('5')->consistency)->toBe(4.0)
        ->and($version('5')->status)->toBe('eligible')
        ->and($version('7')->status)->toBe('excluded')
        ->and($version('2200')->status)->toBe('excluded')
        ->and($version('100')->status)->toBe('eligible');
});

it('marks units without a category as excluded and refuses an invalid subalterno', function () {
    $run = catImport('T001', catFile([
        catRow('1', '1', '1', 'VIA A n. 1 Piano T', 'A02', '4 vani'),
        catRow('1', '1', '2', 'VIA A n. 1 Piano 1', '', '4 vani'),
        catRow('1', '1', '0', 'VIA A n. 1 Piano 2', 'A02', '4 vani'),
        catRow('1', '1', 'X', 'VIA A n. 1 Piano 3', 'A02', '4 vani'),
    ]));
    $release = catDraft('T001');

    expect($run->rows_imported)->toBe(2)
        ->and($run->rows_rejected)->toBe(2)
        ->and($run->issues()->pluck('code')->all())->toBe(['row-sub-invalid', 'row-sub-invalid'])
        ->and(DB::table('cadastral_unit_versions')->where('catalog_release_id', $release->id)->where('status', 'excluded')->value('status_reason'))->toBe('Categoria mancante');
});
