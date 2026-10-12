<?php

namespace App\Trova;

use App\Models\CatalogRelease;
use App\Models\ImportIssue;
use App\Models\ImportRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Gives the parcels of a draft edition their outline and their search point, from a GeoJSON of cadastral parcels
 * (for example the Agenzia delle Entrate cartography). A feature finds its parcel by section, sheet and number inside
 * the Comune of the edition: the Comune code only says which Comune the file belongs to.
 *
 * Sheet and number are read as GeoJsonSource::reference() says (mapped fields or national reference).
 * The file is streamed and its coordinates moved from the EPSG of the run (default 6706, ETRF2000) to 4326.
 */
final class ParcelGeometryImporter
{
    public const PARSER = 'parcel-geojson-1';

    public const CHUNK = 200;

    public const SOURCE = 'cadastral-map';

    /**
     * @param  array{sheet?: string, parcel?: string, section?: string}  $mapping  property names; empty = national reference
     *
     * @throws RuntimeException
     */
    public function prepare(CatalogRelease $release, string $storedPath, string $originalName, array $mapping = [], ?int $epsg = null): ImportRun
    {
        if ($release->status !== 'draft') {
            throw new RuntimeException('Le sagome si caricano su una bozza: importa prima il file SISTER.');
        }
        if (! DB::table('cadastral_unit_versions')->where('catalog_release_id', $release->id)->exists()) {
            throw new RuntimeException('La bozza non ha ancora unità: aspetta la fine dell’import SISTER.');
        }
        $path = Storage::disk('local')->path($storedPath);
        if (! is_file($path)) {
            throw new RuntimeException('File non trovato.');
        }
        $epsg = self::checkFile($path, $epsg);

        return ImportRun::query()->create([
            'municipality_id' => $release->municipality_id,
            'catalog_release_id' => $release->id,
            'source_name' => 'parcel-geometry',
            'original_filename' => $originalName,
            'private_path' => $storedPath,
            'sha256' => hash_file('sha256', $path),
            'parser_version' => self::PARSER,
            'status' => 'queued',
            'summary' => ['mapping' => array_filter($mapping, fn ($v) => is_string($v) && trim($v) !== ''), 'epsg' => $epsg],
        ]);
    }

    /**
     * EPSG to use (given, declared in the file, or ETRF2000) after checking that the sample falls in Italy.
     *
     * @throws RuntimeException
     */
    public static function checkFile(string $path, ?int $epsg): int
    {
        $epsg ??= GeoJsonSource::declaredEpsg($path) ?? GeoJsonSource::DEFAULT_EPSG;
        GeoJsonSource::assertEpsg($epsg);
        $sample = GeoJsonSource::sample($path, 20);
        if ($sample === []) {
            throw new RuntimeException('Il file non contiene sagome (serve un GeoJSON FeatureCollection).');
        }
        $italy = GeoJsonSource::insideItaly($sample, $epsg);
        if ($italy === null) {
            throw new RuntimeException('Le prime sagome del file non sono poligoni (Polygon o MultiPolygon).');
        }
        if ($italy['inside'] === 0) {
            throw new RuntimeException("Con EPSG:{$epsg} le sagome non cadono in Italia: controlla il sistema di riferimento del file.");
        }

        return $epsg;
    }

    /** An earlier upload of the very same file on this draft, if any. */
    public static function duplicateOf(int $releaseId, string $source, string $sha256): ?ImportRun
    {
        return ImportRun::query()->where('catalog_release_id', $releaseId)->where('source_name', $source)->where('sha256', $sha256)
            ->whereIn('status', ['queued', 'running', 'done'])->latest('id')->first();
    }

    /** Never throws: a failure is recorded on the run. */
    public function process(ImportRun $run): ImportRun
    {
        set_time_limit(0);
        $guard = new ImportGuard($run);

        try {
            $this->run($run);
        } catch (RuntimeException $e) {
            $this->fail($run, $e->getMessage());
        } catch (Throwable $e) {
            report($e);
            $this->fail($run, 'Import interrotto da un errore.');
        }
        $guard->done = true;

        return $run->refresh();
    }

    private function fail(ImportRun $run, string $message): void
    {
        $run->forceFill(['status' => 'failed', 'finished_at' => now()])->save();
        $this->issue($run, 'error', 'import-failed', $message);
    }

    private function run(ImportRun $run): void
    {
        $release = CatalogRelease::query()->with('municipality')->findOrFail($run->catalog_release_id);
        $code = $release->municipality->cadastral_code;
        $mapping = $run->summary['mapping'] ?? [];
        $run->forceFill(['status' => 'running', 'started_at' => now()])->save();

        $epsg = (int) ($run->summary['epsg'] ?? GeoJsonSource::DEFAULT_EPSG);
        $read = $rejected = $matched = 0;
        $reported = 0;
        $notFound = [];
        $seen = [];
        $rows = [];

        $flush = function () use (&$rows, &$matched, &$notFound, &$read, &$rejected, $release, $epsg, $run) {
            if ($rows !== []) {
                [$ok, $missing] = $this->write($release->municipality_id, $release->id, $rows, $epsg);
                $matched += $ok;
                array_push($notFound, ...$missing);
                $rows = [];
            }
            $run->forceFill(['rows_read' => $read, 'rows_imported' => $matched, 'rows_rejected' => $rejected])->save();
        };

        foreach (GeoJsonSource::features(Storage::disk('local')->path((string) $run->private_path)) as $index => $feature) {
            $read++;
            $geometry = $feature['geometry'] ?? null;
            $props = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];

            $ref = GeoJsonSource::reference($props, $mapping, $code);
            if (is_string($ref) || ! is_array($geometry) || ! in_array($geometry['type'] ?? null, ['Polygon', 'MultiPolygon'], true)) {
                $rejected++;
                if ($reported++ < CatalogImporter::MAX_ISSUES) {
                    $this->issue($run, 'warning', 'feature-rejected', is_string($ref) ? $ref : 'La geometria deve essere un Polygon o un MultiPolygon.', (int) $index + 1);
                }

                continue;
            }

            [$section, $sheet, $number] = $ref;
            $seen["{$section}|{$sheet}|{$number}"] = true;
            $rows[] = [$section, $sheet, $number, json_encode($geometry, JSON_THROW_ON_ERROR)];
            if (count($rows) >= self::CHUNK) {
                $flush();
            }
        }
        $flush();

        $notFound = array_values(array_unique($notFound));
        if ($notFound !== []) {
            $this->issue($run, 'warning', 'parcel-not-in-edition', count($notFound).' sagome ignorate perché la particella non ha unità in questa bozza (per esempio: '.implode('; ', array_slice($notFound, 0, 3)).').');
        }

        $coverage = CatalogImporter::coverage($release->id);
        $summary = $run->summary ?? [];
        $summary['features'] = $read;
        $summary['parcels_in_file'] = count($seen);
        $summary['not_in_edition'] = count($notFound);
        $summary['coverage'] = $coverage;

        $run->forceFill([
            'status' => $matched > 0 ? 'done' : 'failed',
            'rows_read' => $read,
            'rows_imported' => $matched,
            'rows_rejected' => $rejected,
            'summary' => $summary,
            'finished_at' => now(),
        ])->save();

        if ($matched === 0) {
            $this->issue($run, 'error', 'no-match', 'Nessuna sagoma corrisponde alle particelle della bozza. Controlla le proprietà di foglio e particella.');
        }
    }

    /**
     * @param  array<string, mixed>  $props
     * @param  array<string, string>  $mapping
     * @return array{0: string, 1: string, 2: string}|string section, sheet, number — or the reason it cannot be read
     */
    private function reference(array $props, array $mapping, string $code): array|string
    {
        return GeoJsonSource::reference($props, $mapping, $code);
    }

    /**
     * Stores outlines and search points for the parcels that have units in the edition.
     *
     * @param  list<array{0: string, 1: string, 2: string, 3: string}>  $rows  section, sheet, number, GeoJSON
     * @return array{0: int, 1: list<string>} parcels placed, labels of those not found
     */
    private function write(int $municipalityId, int $releaseId, array $rows, int $epsg = 4326): array
    {
        $values = $bind = [];
        foreach ($rows as [$section, $sheet, $number, $json]) {
            $values[] = '(?::text, ?::text, ?::text, ?::text)';
            array_push($bind, $section, $sheet, $number, $json);
        }
        array_push($bind, $epsg, $municipalityId, $releaseId, $releaseId, $releaseId);

        $result = DB::select(
            'WITH f(section, sheet, number, g) AS (VALUES '.implode(',', $values).'),
             geom AS (
                 SELECT section, sheet, number,
                        '.GeoJsonSource::geometrySql('g').' AS geom
                 FROM f
             ),
             target AS (
                 SELECT p.id AS parcel_id, p.section, p.sheet, p.number, ST_Multi(ST_Union(gm.geom)) AS geom
                 FROM geom gm
                 JOIN parcels p ON p.municipality_id = ? AND p.section = gm.section AND p.sheet = gm.sheet AND p.number = gm.number
                 WHERE NOT ST_IsEmpty(gm.geom) AND ST_Intersects(gm.geom, '.GeoJsonSource::italyEnvelopeSql().')
                   AND EXISTS (SELECT 1 FROM cadastral_units u JOIN cadastral_unit_versions v ON v.cadastral_unit_id = u.id AND v.catalog_release_id = ? WHERE u.parcel_id = p.id)
                 GROUP BY p.id, p.section, p.sheet, p.number
             ),
             pv AS (
                 INSERT INTO parcel_versions (parcel_id, catalog_release_id, area_sqm, boundary, created_at, updated_at)
                 SELECT parcel_id, ?, ST_Area(geom::geography), geom, now(), now() FROM target
                 ON CONFLICT (parcel_id, catalog_release_id) DO UPDATE SET boundary = EXCLUDED.boundary, area_sqm = EXCLUDED.area_sqm, updated_at = now()
                 RETURNING parcel_id
             ),
             sp AS (
                 INSERT INTO parcel_search_points (parcel_id, catalog_release_id, source, location, created_at, updated_at)
                 SELECT parcel_id, ?, \''.self::SOURCE."', ST_PointOnSurface(geom), now(), now() FROM target
                 ON CONFLICT (parcel_id, catalog_release_id) DO UPDATE SET source = EXCLUDED.source, location = EXCLUDED.location, updated_at = now()
                 RETURNING parcel_id
             )
             SELECT f.section, f.sheet, f.number, t.parcel_id IS NOT NULL AS ok
             FROM f LEFT JOIN target t ON t.section = f.section AND t.sheet = f.sheet AND t.number = f.number",
            $bind,
        );

        $ok = $missing = [];
        foreach ($result as $row) {
            $label = ($row->section !== '' ? "{$row->section}/" : '')."foglio {$row->sheet} n. {$row->number}";
            $row->ok ? $ok[$label] = true : $missing[] = $label;
        }

        return [count($ok), $missing];
    }

    private function issue(ImportRun $run, string $severity, string $code, string $message, ?int $row = null): void
    {
        ImportIssue::query()->create(['import_run_id' => $run->id, 'severity' => $severity, 'code' => $code, 'message' => $message, 'row_number' => $row]);
    }
}
