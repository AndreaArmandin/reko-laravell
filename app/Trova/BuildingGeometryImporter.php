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
 * Gives a draft edition its building footprints (fabbricati) from a GeoJSON, like ParcelGeometryImporter does for parcels.
 *
 * Each footprint is linked to its parcel by the mapped fields (sheet/number or national reference) or, when the file has
 * none (for the whole file, or for a single feature missing them), to the parcels of the edition it overlaps (at least 20% of the footprint): load the parcel outlines first.
 * The file is the complete set for the edition: footprints already in the draft (copied from the previous edition or
 * from an earlier upload) are replaced. A footprint with no parcel of the edition is skipped.
 */
final class BuildingGeometryImporter
{
    public const PARSER = 'building-geojson-1';

    public const CHUNK = 200;

    public const SOURCE = 'building-geometry';

    /** Share of the footprint that must lie on a parcel when linking by position. */
    public const OVERLAP = 0.2;

    /**
     * @param  array{reference?: string, sheet?: string, parcel?: string, section?: string, id?: string}  $mapping
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
        $mapping = array_filter($mapping, fn ($v) => is_string($v) && trim($v) !== '');
        $byPosition = ! isset($mapping['reference']) && ! (isset($mapping['sheet'], $mapping['parcel']));
        if ($byPosition && ! DB::table('parcel_versions')->where('catalog_release_id', $release->id)->whereNotNull('boundary')->exists()) {
            throw new RuntimeException('Senza campi di foglio e particella i fabbricati si abbinano per posizione: carica prima le sagome delle particelle.');
        }
        $path = Storage::disk('local')->path($storedPath);
        if (! is_file($path)) {
            throw new RuntimeException('File non trovato.');
        }
        $epsg = ParcelGeometryImporter::checkFile($path, $epsg);

        return ImportRun::query()->create([
            'municipality_id' => $release->municipality_id,
            'catalog_release_id' => $release->id,
            'source_name' => self::SOURCE,
            'original_filename' => $originalName,
            'private_path' => $storedPath,
            'sha256' => hash_file('sha256', $path),
            'parser_version' => self::PARSER,
            'status' => 'queued',
            'summary' => ['mapping' => $mapping, 'epsg' => $epsg, 'by_position' => $byPosition],
        ]);
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
        $byPosition = (bool) ($run->summary['by_position'] ?? false);
        $epsg = (int) ($run->summary['epsg'] ?? GeoJsonSource::DEFAULT_EPSG);
        $run->forceFill(['status' => 'running', 'started_at' => now()])->save();

        // The file replaces the footprints of the draft
        DB::table('building_parcel_links')->where('catalog_release_id', $release->id)->delete();
        DB::table('building_versions')->where('catalog_release_id', $release->id)->delete();

        $outlines = DB::table('parcel_versions')->where('catalog_release_id', $release->id)->whereNotNull('boundary')->exists();
        $read = $rejected = $linked = $unlinked = $reported = $positioned = 0;
        $rows = [];
        $flush = function () use (&$rows, &$linked, &$unlinked, &$read, &$rejected, $release, $epsg, $run) {
            if ($rows !== []) {
                [$ok, $total] = $this->write($release->municipality_id, $release->id, $rows, $epsg);
                $linked += $ok;
                $unlinked += $total - $ok;
                $rows = [];
            }
            $run->forceFill(['rows_read' => $read, 'rows_imported' => $linked, 'rows_rejected' => $rejected])->save();
        };

        foreach (GeoJsonSource::features(Storage::disk('local')->path((string) $run->private_path)) as $index => $feature) {
            $read++;
            $geometry = $feature['geometry'] ?? null;
            $props = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
            if (! is_array($geometry) || ! in_array($geometry['type'] ?? null, ['Polygon', 'MultiPolygon'], true)) {
                $rejected++;
                if ($reported++ < CatalogImporter::MAX_ISSUES) {
                    $this->issue($run, 'warning', 'feature-rejected', 'La geometria deve essere un Polygon o un MultiPolygon.', $index + 1);
                }

                continue;
            }

            $ref = $byPosition ? [null, null, null] : GeoJsonSource::reference($props, $mapping, $code);
            // A footprint without the mapped fields still finds its parcel by position, when the outlines are there
            if (is_string($ref) && $outlines) {
                $ref = [null, null, null];
                $positioned++;
            }
            if (is_string($ref)) {
                $rejected++;
                if ($reported++ < CatalogImporter::MAX_ISSUES) {
                    $this->issue($run, 'warning', 'feature-rejected', $ref, $index + 1);
                }

                continue;
            }

            $json = json_encode($geometry, JSON_THROW_ON_ERROR);
            $id = isset($mapping['id']) && is_scalar($props[$mapping['id']] ?? null) && trim((string) $props[$mapping['id']]) !== ''
                ? 'f:'.mb_substr(trim((string) $props[$mapping['id']]), 0, 180)
                : 'g:'.md5($json);
            $rows[] = [$index, $id, ...$ref, $json];
            if (count($rows) >= self::CHUNK) {
                $flush();
            }
        }
        $flush();

        if ($unlinked > 0) {
            $this->issue($run, 'warning', 'building-not-linked', "{$unlinked} fabbricati ignorati: non cadono su una particella con unità in questa bozza (o sono fuori dall’Italia).");
        }

        $summary = $run->summary ?? [];
        $summary['features'] = $read;
        $summary['unlinked'] = $unlinked;
        $summary['by_position_features'] = $byPosition ? $read - $rejected : $positioned;
        $summary['buildings'] = DB::table('building_versions')->where('catalog_release_id', $release->id)->count();
        $run->forceFill([
            'status' => $linked > 0 ? 'done' : 'failed',
            'rows_read' => $read, 'rows_imported' => $linked, 'rows_rejected' => $rejected,
            'summary' => $summary, 'finished_at' => now(),
        ])->save();

        if ($linked === 0) {
            $this->issue($run, 'error', 'no-match', $read === 0 ? 'Il file non contiene sagome.' : 'Nessun fabbricato corrisponde alle particelle della bozza. Controlla i campi e il sistema di riferimento.');
        }
    }

    /**
     * @param  list<array{0: int, 1: string, 2: ?string, 3: ?string, 4: ?string, 5: string}>  $rows  index, id, section, sheet, number, GeoJSON
     * @return array{0: int, 1: int} features linked to a parcel, features in the chunk
     */
    private function write(int $municipalityId, int $releaseId, array $rows, int $epsg): array
    {
        $values = $bind = [];
        foreach ($rows as [$index, $id, $section, $sheet, $number, $json]) {
            $values[] = '(?::int, ?::text, ?::text, ?::text, ?::text, ?::text)';
            array_push($bind, $index, $id, $section, $sheet, $number, $json);
        }
        $units = 'EXISTS (SELECT 1 FROM cadastral_units u JOIN cadastral_unit_versions v ON v.cadastral_unit_id = u.id AND v.catalog_release_id = ? WHERE u.parcel_id = %s)';
        // epsg · link (Comune, unità della bozza, sagome della bozza) · buildings (Comune) · versions · links
        array_push($bind, $epsg, $municipalityId, $releaseId, $releaseId, $municipalityId, $releaseId, $releaseId);

        $row = DB::selectOne(
            'WITH f(idx, bid, section, sheet, number, g) AS (VALUES '.implode(',', $values).'),
             ok AS (
                 SELECT idx, bid, section, sheet, number, '.GeoJsonSource::geometrySql('g').' AS geom FROM f
             ),
             good AS (SELECT * FROM ok WHERE NOT ST_IsEmpty(geom) AND ST_Intersects(geom, '.GeoJsonSource::italyEnvelopeSql().')),
             link AS (
                 SELECT g.idx, p.id AS parcel_id FROM good g
                 JOIN parcels p ON p.municipality_id = ? AND p.section = g.section AND p.sheet = g.sheet AND p.number = g.number
                 WHERE g.sheet IS NOT NULL AND '.sprintf($units, 'p.id').'
                 UNION
                 SELECT g.idx, pv.parcel_id FROM good g
                 JOIN parcel_versions pv ON pv.catalog_release_id = ? AND pv.boundary IS NOT NULL AND ST_Intersects(pv.boundary, g.geom)
                 WHERE g.sheet IS NULL AND ST_Area(ST_Intersection(pv.boundary, g.geom)) >= '.self::OVERLAP.' * ST_Area(g.geom)
             ),
             b AS (
                 INSERT INTO buildings (municipality_id, cadastral_building_id, created_at, updated_at)
                 SELECT DISTINCT ?::bigint, g.bid, now(), now() FROM good g WHERE g.idx IN (SELECT idx FROM link)
                 ON CONFLICT (municipality_id, cadastral_building_id) WHERE cadastral_building_id IS NOT NULL DO UPDATE SET updated_at = now()
                 RETURNING id, cadastral_building_id
             ),
             bv AS (
                 INSERT INTO building_versions (building_id, catalog_release_id, area_sqm, footprint, created_at, updated_at)
                 SELECT b.id, ?, ST_Area(ST_Multi(ST_Union(g.geom))::geography), ST_Multi(ST_Union(g.geom)), now(), now()
                 FROM b JOIN good g ON g.bid = b.cadastral_building_id WHERE g.idx IN (SELECT idx FROM link) GROUP BY b.id
                 ON CONFLICT (building_id, catalog_release_id) DO UPDATE SET footprint = ST_Multi(ST_Union(building_versions.footprint, EXCLUDED.footprint)),
                     area_sqm = ST_Area(ST_Union(building_versions.footprint, EXCLUDED.footprint)::geography), updated_at = now()
                 RETURNING building_id
             ),
             bl AS (
                 INSERT INTO building_parcel_links (catalog_release_id, building_id, parcel_id, created_at, updated_at)
                 SELECT DISTINCT ?::bigint, b.id, l.parcel_id, now(), now() FROM link l JOIN good g ON g.idx = l.idx JOIN b ON b.cadastral_building_id = g.bid
                 ON CONFLICT DO NOTHING
                 RETURNING id
             )
             SELECT (SELECT count(DISTINCT idx) FROM link) AS linked, (SELECT count(*) FROM bv) AS versions, (SELECT count(*) FROM bl) AS links',
            $bind,
        );

        return [(int) $row->linked, count($rows)];
    }

    private function issue(ImportRun $run, string $severity, string $code, string $message, ?int $row = null): void
    {
        ImportIssue::query()->create(['import_run_id' => $run->id, 'severity' => $severity, 'code' => $code, 'message' => $message, 'row_number' => $row]);
    }
}
