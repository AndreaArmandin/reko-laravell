<?php

namespace App\Trova;

use App\Models\ImportIssue;
use App\Models\ImportRun;
use App\Models\Municipality;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;

/**
 * Loads search zones (quartieri, frazioni) from a GeoJSON FeatureCollection into geographic_zones.
 * Same file format as Trova's data/municipal-search-zones.generated.json:
 * every feature carries properties id, municipalityCode, name and optionally notice, method, sourceUrl.
 * Files from other sources can name those properties differently ($nameProperty, $idProperty); a missing id
 * becomes "{cadastral code}-{row}".
 *
 * The same class serves the console command and the admin upload page.
 */
final class ZoneImporter
{
    public const PARSER = 'search-zones-geojson-1';

    public const KIND = 'zona-di-ricerca';

    /**
     * @param  string|null  $only  import only the features of this cadastral code
     * @param  string|null  $into  store them under this Comune instead of the one named in the file, only with a single source code
     * @param  string|null  $filename  name of the original file, when $path is a temporary copy
     *
     * @throws RuntimeException when the file or the target Comune cannot be used
     */
    public function import(
        string $path,
        ?string $only = null,
        ?string $into = null,
        bool $dryRun = false,
        bool $replace = false,
        string $nameProperty = 'name',
        string $idProperty = 'id',
        ?string $filename = null,
    ): ImportRun {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("File non leggibile: {$path}");
        }
        $raw = (string) file_get_contents($path);

        try {
            $data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Il file non è un JSON valido.');
        }
        if (! is_array($data) || ($data['type'] ?? null) !== 'FeatureCollection' || ! is_array($data['features'] ?? null)) {
            throw new RuntimeException('Il file deve essere un GeoJSON FeatureCollection.');
        }

        $only = $only === null ? null : strtoupper($only);
        $into = $into === null ? null : strtoupper($into);

        /** @var array<int, array<string, mixed>> $features */
        $features = array_values(array_filter($data['features'], fn ($f) => is_array($f)
            && ($only === null || strtoupper((string) ($f['properties']['municipalityCode'] ?? '')) === $only)));
        if ($features === []) {
            throw new RuntimeException('Nessuna zona da importare'.($only ? " per {$only}" : '').'.');
        }

        $codes = array_values(array_unique(array_map(fn ($f) => strtoupper((string) ($f['properties']['municipalityCode'] ?? '')), $features)));
        if ($into !== null && count($codes) > 1) {
            throw new RuntimeException('Il file contiene zone di più Comuni ('.implode(', ', $codes).'): indica quale importare.');
        }

        $run = new ImportRun([
            'source_name' => 'search-zones',
            'original_filename' => $filename ?? basename($path),
            'sha256' => hash('sha256', $raw),
            'parser_version' => self::PARSER,
            'status' => 'running',
            'rows_read' => count($features),
            'started_at' => now(),
        ]);

        /** @var list<ImportIssue> $issues */
        $issues = [];
        $imported = 0;

        // Le zone stanno in una transazione: se qualcosa va storto non resta nulla a metà.
        DB::beginTransaction();
        try {
            $municipalities = [];
            foreach ($codes as $code) {
                $target = $into ?? $code;
                $municipalities[$code] = Municipality::query()->where('cadastral_code', $target)->first()
                    ?? throw new RuntimeException("Comune {$target} non presente nel database.");
            }
            if (count($municipalities) === 1) {
                $run->municipality_id = reset($municipalities)->id;
            }

            if ($replace) {
                foreach ($municipalities as $municipality) {
                    DB::table('geographic_zones')->where('municipality_id', $municipality->id)->where('kind', self::KIND)->delete();
                }
            }

            $seen = [];
            foreach ($features as $index => $feature) {
                $row = $index + 1;
                $props = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
                $municipality = $municipalities[strtoupper((string) ($props['municipalityCode'] ?? ''))] ?? null;
                $name = trim(self::scalar($props[$nameProperty] ?? null));

                if ($municipality === null || $name === '') {
                    $issues[] = $this->issue('error', 'zone-without-identity', 'La zona non ha un nome'.($municipality === null ? ' o un Comune' : '').'.', $row, $props);

                    continue;
                }
                $code = trim(self::scalar($props[$idProperty] ?? $feature['id'] ?? null))
                    ?: $municipality->cadastral_code.'-'.sprintf('%02d', $row);
                $code = mb_substr($code, 0, 40);
                if (isset($seen[$code])) {
                    $issues[] = $this->issue('error', 'duplicate-zone', "Zona {$code} ripetuta nel file.", $row, $props);

                    continue;
                }
                $seen[$code] = true;

                $geometry = $this->geometry($feature['geometry'] ?? null, $municipality);
                if (is_string($geometry)) {
                    $issues[] = $this->issue('error', 'invalid-geometry', $geometry, $row, ['id' => $code, 'name' => $name]);

                    continue;
                }
                if ($geometry['clipped'] > 1) {
                    $issues[] = $this->issue('warning', 'clipped-to-municipality', sprintf('%.0f m² della zona %s erano fuori dal Comune e sono stati tagliati.', $geometry['clipped'], $code), $row, ['id' => $code]);
                }

                DB::statement(
                    'INSERT INTO geographic_zones (municipality_id, code, name, kind, indicative, notice, source, boundary, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ST_GeomFromEWKT(?), now(), now())
                     ON CONFLICT (municipality_id, code) DO UPDATE SET name = EXCLUDED.name, kind = EXCLUDED.kind, indicative = EXCLUDED.indicative,
                         notice = EXCLUDED.notice, source = EXCLUDED.source, boundary = EXCLUDED.boundary, updated_at = now()',
                    [
                        $municipality->id,
                        $code,
                        mb_substr($name, 0, 255),
                        self::KIND,
                        (bool) ($props['indicative'] ?? true),
                        (string) ($props['notice'] ?? 'Zona di ricerca indicativa · non è un confine catastale o amministrativo'),
                        mb_substr((string) ($props['sourceUrl'] ?? $props['method'] ?? basename($path)), 0, 255),
                        $geometry['ewkt'],
                    ],
                );
                $imported++;
            }

            $dryRun || $imported === 0 ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $run->forceFill([
            'rows_imported' => $imported,
            'rows_rejected' => $run->rows_read - $imported,
            'status' => $dryRun ? 'dry-run' : ($imported === 0 ? 'failed' : 'done'),
            'finished_at' => now(),
        ]);

        // Un dry-run non lascia tracce; un import vero (anche fallito) sì, con i suoi problemi.
        if (! $dryRun) {
            DB::transaction(function () use ($run, $issues) {
                $run->save();
                $run->issues()->saveMany($issues);
            });
        }
        $run->setRelation('issues', new Collection($issues));

        return $run;
    }

    private static function scalar(mixed $value): string
    {
        return is_string($value) || is_int($value) || is_float($value) ? (string) $value : '';
    }

    /**
     * Returns an error message, or the normalised geometry as EWKT plus the area (m²) clipped away.
     *
     * @return string|array{ewkt: string, clipped: float}
     */
    private function geometry(mixed $geometry, Municipality $municipality): string|array
    {
        if (! is_array($geometry) || ! in_array($geometry['type'] ?? null, ['Polygon', 'MultiPolygon'], true)) {
            return 'La geometria deve essere un Polygon o un MultiPolygon.';
        }

        $params = [json_encode($geometry, JSON_THROW_ON_ERROR)];
        try {
            $rows = DB::transaction(fn () => DB::select(
                'WITH g AS (
                    SELECT ST_Multi(ST_CollectionExtract(ST_MakeValid(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326)), 3)) AS geom
                 ), c AS (
                    SELECT g.geom AS original,
                           CASE WHEN m.boundary IS NULL THEN g.geom
                                ELSE ST_Multi(ST_CollectionExtract(ST_Intersection(g.geom, m.boundary), 3)) END AS geom
                    FROM g LEFT JOIN municipalities m ON m.id = ?
                 )
                 SELECT ST_AsEWKT(geom) AS ewkt, ST_IsEmpty(geom) AS empty,
                        ST_Area(original::geography) - ST_Area(geom::geography) AS clipped
                 FROM c',
                [...$params, $municipality->id],
            ));
        } catch (QueryException) {
            return 'Geometria non leggibile.';
        }

        $row = $rows[0] ?? null;
        if ($row === null || $row->ewkt === null || $row->empty) {
            return 'La zona è vuota o cade fuori dal Comune.';
        }

        return ['ewkt' => (string) $row->ewkt, 'clipped' => (float) $row->clipped];
    }

    /**
     * @param  array<mixed>  $payload
     */
    private function issue(string $severity, string $code, string $message, int $row, array $payload): ImportIssue
    {
        return new ImportIssue([
            'severity' => $severity,
            'code' => $code,
            'message' => $message,
            'row_number' => $row,
            'payload' => $payload,
        ]);
    }
}
