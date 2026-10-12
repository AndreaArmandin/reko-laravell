<?php

namespace App\Trova;

use Illuminate\Support\Facades\DB;
use JsonMachine\Items;
use JsonMachine\JsonDecoder\ExtJsonDecoder;
use RuntimeException;
use Throwable;

/**
 * Reads a GeoJSON FeatureCollection of cadastral outlines (parcels or buildings) and finds, in each feature,
 * the parcel it belongs to. Shared by ParcelGeometryImporter and BuildingGeometryImporter.
 *
 * Large files are streamed with halaxa/json-machine when installed, so a whole city does not have to fit in memory.
 * Coordinates can be in any EPSG known to PostGIS (default 6706, ETRF2000 geographic): they are moved to 4326.
 */
final class GeoJsonSource
{
    public const DEFAULT_EPSG = 6706;

    /** Generous box around Italy (lng/lat 4326): what falls outside is a wrong reference system. */
    public const ITALY = [6.0, 35.0, 19.0, 48.0];

    /** Property names that carry the national reference "D205_000100.123" (also inside an INSPIRE localId). */
    public const REFERENCE_NAMES = ['NATIONALCADASTRALREFERENCE', 'NATIONALCADASTALREFERENCE', 'INSPIREID_LOCALID', 'LOCALID', 'RIF_CAT', 'CODICE'];

    public const SHEET_NAMES = ['FOGLIO', 'FOG', 'FG', 'NUM_FOGLIO', 'SHEET'];

    public const PARCEL_NAMES = ['NUMERO', 'PARTICELLA', 'MAPPALE', 'NUM_PART', 'NUM', 'PARCEL', 'LABEL'];

    public const SECTION_NAMES = ['SEZIONE', 'SEZ', 'SECTION'];

    public const ID_NAMES = ['ID', 'GML_ID', 'IDENTIFICATIVO'];

    /**
     * Features one at a time.
     *
     * @return \Generator<int, array<string, mixed>>
     *
     * @throws RuntimeException
     */
    public static function features(string $path): \Generator
    {
        if (! is_file($path)) {
            throw new RuntimeException('File non trovato.');
        }

        if (class_exists(Items::class)) {
            try {
                foreach (Items::fromFile($path, ['pointer' => '/features', 'decoder' => new ExtJsonDecoder(true)]) as $index => $feature) {
                    yield (int) $index => is_array($feature) ? $feature : [];
                }
            } catch (Throwable $e) {
                if ($e instanceof RuntimeException && ! str_starts_with($e::class, 'JsonMachine')) {
                    throw $e;
                }
                throw new RuntimeException('Il file non è un GeoJSON valido.');
            }

            return;
        }

        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data) || ($data['type'] ?? null) !== 'FeatureCollection' || ! is_array($data['features'] ?? null)) {
            throw new RuntimeException('Il file deve essere un GeoJSON FeatureCollection.');
        }
        foreach ($data['features'] as $index => $feature) {
            yield (int) $index => is_array($feature) ? $feature : [];
        }
    }

    /** @return list<array<string, mixed>> the first $n features */
    public static function sample(string $path, int $n = 50): array
    {
        $out = [];
        foreach (self::features($path) as $feature) {
            $out[] = $feature;
            if (count($out) >= $n) {
                break;
            }
        }

        return $out;
    }

    /** EPSG declared in the "crs" member near the top of the file, if any. */
    public static function declaredEpsg(string $path): ?int
    {
        $head = (string) @file_get_contents($path, false, null, 0, 65536);
        if (! preg_match('/"crs"\s*:\s*\{.*?"name"\s*:\s*"([^"]+)"/s', $head, $m)) {
            return null;
        }
        if (preg_match('/CRS84$/i', $m[1])) {
            return 4326;
        }

        return preg_match('/EPSG:+(\d{4,6})$/i', $m[1], $e) ? (int) $e[1] : null;
    }

    /**
     * Properties found in the sample, each with a sample value.
     *
     * @param  list<array<string, mixed>>  $features
     * @return array<string, string>
     */
    public static function properties(array $features): array
    {
        $found = [];
        foreach ($features as $feature) {
            foreach ((is_array($feature['properties'] ?? null) ? $feature['properties'] : []) as $name => $value) {
                if (is_scalar($value) && trim((string) $value) !== '' && ! isset($found[$name])) {
                    $found[(string) $name] = mb_substr(trim((string) $value), 0, 40);
                } elseif (! isset($found[$name])) {
                    $found[(string) $name] = '';
                }
            }
        }

        return $found;
    }

    /**
     * Best guess of the mapping from the property names.
     *
     * @param  list<string>  $names
     * @return array{reference: string, sheet: string, parcel: string, section: string, id: string}
     */
    public static function detect(array $names): array
    {
        $pick = function (array $known) use ($names): string {
            foreach ($known as $k) {
                foreach ($names as $name) {
                    if (strtoupper($name) === $k) {
                        return $name;
                    }
                }
            }

            return '';
        };
        $sheet = $pick(self::SHEET_NAMES);
        $parcel = $sheet === '' ? '' : $pick(array_diff(self::PARCEL_NAMES, ['LABEL']));

        return [
            'reference' => $pick(self::REFERENCE_NAMES),
            'sheet' => $sheet,
            'parcel' => $parcel,
            'section' => $pick(self::SECTION_NAMES),
            'id' => $pick(self::ID_NAMES),
        ];
    }

    /**
     * Section, sheet and number of the parcel the feature belongs to, or the reason it cannot be read.
     * The reference field wins; otherwise sheet + parcel; with nothing mapped the usual national names are tried.
     *
     * @param  array<string, mixed>  $props
     * @param  array<string, string>  $mapping
     * @return array{0: string, 1: string, 2: string}|string
     */
    public static function reference(array $props, array $mapping, string $code): array|string
    {
        $mapping = array_filter($mapping, fn ($v) => is_string($v) && trim($v) !== '');

        if (isset($mapping['sheet'], $mapping['parcel']) && ! isset($mapping['reference'])) {
            $sheet = $props[$mapping['sheet']] ?? null;
            $number = $props[$mapping['parcel']] ?? null;
            if (! is_scalar($sheet) || ! is_scalar($number) || trim((string) $sheet) === '' || trim((string) $number) === '') {
                return 'Foglio o particella mancanti nei campi indicati.';
            }
            $section = isset($mapping['section']) && is_scalar($props[$mapping['section']] ?? null) ? strtoupper(trim((string) $props[$mapping['section']])) : '';

            return [$section === '_' ? '' : $section, CatalogSearch::cadastralId((string) $sheet), CatalogSearch::cadastralId((string) $number)];
        }

        $names = isset($mapping['reference']) ? [$mapping['reference']] : self::REFERENCE_NAMES;
        foreach ($names as $name) {
            $national = $props[$name] ?? null;
            if (is_string($national) && preg_match('/([A-Z]\d{3})([A-Z_])(\d{4})(\w)(\w)\.(\w+)$/i', trim($national), $m)) {
                if (strtoupper($m[1]) !== $code) {
                    return "La particella {$national} è di un altro Comune ({$m[1]}).";
                }

                return [$m[2] === '_' ? '' : strtoupper($m[2]), CatalogSearch::cadastralId($m[3]), CatalogSearch::cadastralId($m[6])];
            }
        }

        return isset($mapping['reference'])
            ? "Il campo {$mapping['reference']} non contiene un riferimento catastale come {$code}_000100.123."
            : 'Non trovo foglio e particella: indica i campi che li contengono.';
    }

    /** Metric systems tried when the file declares none: ETRF2000 UTM 32/33, then Gauss-Boaga west/east. */
    public const METRIC_EPSG = [6707, 6708, 3003, 3004];

    /**
     * EPSG of the file: the declared crs, otherwise guessed from the sample: degrees → 6706,
     * metres → the first metric system that puts most of the sample inside Italy. Null when nothing fits.
     *
     * @param  list<array<string, mixed>>  $features
     */
    public static function guessEpsg(array $features, ?int $declared): ?int
    {
        if ($declared !== null) {
            return $declared;
        }
        $first = null;
        foreach ($features as $f) {
            $c = $f['geometry']['coordinates'] ?? null;
            while (is_array($c) && isset($c[0]) && is_array($c[0])) {
                $c = $c[0];
            }
            if (is_array($c) && isset($c[0], $c[1]) && is_numeric($c[0]) && is_numeric($c[1])) {
                $first = [(float) $c[0], (float) $c[1]];
                break;
            }
        }
        if ($first === null) {
            return self::DEFAULT_EPSG;
        }
        if (abs($first[0]) <= 180 && abs($first[1]) <= 90) {
            return self::DEFAULT_EPSG;
        }
        $best = null;
        $bestInside = 0;
        foreach (self::METRIC_EPSG as $epsg) {
            try {
                $inside = self::insideItaly($features, $epsg)['inside'] ?? 0;
            } catch (\Throwable) {
                continue;
            }
            if ($inside > $bestInside) {
                [$best, $bestInside] = [$epsg, $inside];
            }
        }

        return $best;
    }

    /** @throws RuntimeException when PostGIS does not know the EPSG */
    public static function assertEpsg(int $epsg): void
    {
        if (! DB::table('spatial_ref_sys')->where('srid', $epsg)->exists()) {
            throw new RuntimeException("Il sistema di riferimento EPSG:{$epsg} non è conosciuto dal database.");
        }
    }

    /**
     * How many sample outlines fall in Italy once moved to 4326; null when the sample has no polygon.
     *
     * @param  list<array<string, mixed>>  $features
     */
    public static function insideItaly(array $features, int $epsg): ?array
    {
        $geoms = [];
        foreach ($features as $f) {
            $g = $f['geometry'] ?? null;
            if (is_array($g) && in_array($g['type'] ?? null, ['Polygon', 'MultiPolygon'], true)) {
                $geoms[] = json_encode($g);
            }
            if (count($geoms) >= 20) {
                break;
            }
        }
        if ($geoms === []) {
            return null;
        }
        [$w, $s, $e, $n] = self::ITALY;
        $row = DB::selectOne(
            'SELECT count(*) FILTER (WHERE ST_Intersects(ST_Transform(ST_SetSRID(ST_GeomFromGeoJSON(g), ?::int), 4326), ST_MakeEnvelope(?, ?, ?, ?, 4326))) AS inside, count(*) AS total
             FROM json_array_elements_text(?::json) AS g',
            [$epsg, $w, $s, $e, $n, json_encode($geoms)],
        );

        return ['inside' => (int) $row->inside, 'total' => (int) $row->total];
    }

    /** SQL expression turning the GeoJSON text column $col into a valid 4326 MultiPolygon (srid bound as parameter). */
    public static function geometrySql(string $col): string
    {
        return "ST_Multi(ST_CollectionExtract(ST_MakeValid(ST_Transform(ST_SetSRID(ST_GeomFromGeoJSON({$col}), ?::int), 4326)), 3))";
    }

    public static function italyEnvelopeSql(): string
    {
        [$w, $s, $e, $n] = self::ITALY;

        return "ST_MakeEnvelope({$w}, {$s}, {$e}, {$n}, 4326)";
    }

    /**
     * Sample check before importing: features read, references found and parcels of the edition they hit.
     *
     * @param  list<array<string, mixed>>  $features
     * @param  array<string, string>  $mapping
     * @return array{features: int, readable: int, matched: int, error: ?string}
     */
    public static function sampleMatch(array $features, array $mapping, string $code, int $municipalityId, int $releaseId): array
    {
        $refs = [];
        $error = null;
        foreach ($features as $f) {
            $ref = self::reference(is_array($f['properties'] ?? null) ? $f['properties'] : [], $mapping, $code);
            if (is_array($ref)) {
                $refs[implode('|', $ref)] = $ref;
            } else {
                $error ??= $ref;
            }
        }
        $matched = 0;
        if ($refs !== []) {
            $values = $bind = [];
            foreach ($refs as [$section, $sheet, $number]) {
                $values[] = '(?::text, ?::text, ?::text)';
                array_push($bind, $section, $sheet, $number);
            }
            array_push($bind, $municipalityId, $releaseId);
            $matched = (int) DB::selectOne(
                'WITH f(section, sheet, number) AS (VALUES '.implode(',', $values).')
                 SELECT count(DISTINCT p.id) AS n FROM f JOIN parcels p ON p.municipality_id = ? AND p.section = f.section AND p.sheet = f.sheet AND p.number = f.number
                 WHERE EXISTS (SELECT 1 FROM cadastral_units u JOIN cadastral_unit_versions v ON v.cadastral_unit_id = u.id AND v.catalog_release_id = ? WHERE u.parcel_id = p.id)',
                $bind,
            )->n;
        }

        return ['features' => count($features), 'readable' => count($refs), 'matched' => $matched, 'error' => $error];
    }
}
