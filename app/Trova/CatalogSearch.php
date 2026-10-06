<?php

namespace App\Trova;

use App\Models\Municipality;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class CatalogSearch
{
    public const SORTS = ['best', 'address', 'surface-asc', 'surface-desc', 'distance'];

    public const PAGE_SIZES = [10, 25];

    /** Radius in metres: inclusive bounds and step for the circle filter. */
    public const RADIUS_MIN = 200;

    public const RADIUS_MAX = 5000;

    public const RADIUS_STEP = 50;

    public const RADIUS_DEFAULT = 500;

    // Esegue la ricerca
    public function search(array $input): CatalogSearchResult
    {
        $criteria = self::validate($input);

        // Recupera il comune e l'archivio
        $municipality = Municipality::query()->where('cadastral_code', $criteria['code'])->with('catalog')->first();
        $releaseId = $municipality?->catalog?->catalog_release_id;

        if ($municipality === null) {
            throw new SearchException('Comune non disponibile.');
        }

        if ($releaseId === null) {
            throw new SearchException('Archivio del Comune in preparazione. Riprova tra poco.', retry: true);
        }

        // Errore del database: il dettaglio tecnico va nel log, l'utente legge solo di riprovare.
        // La transazione (un savepoint, se ce n'è già una) evita che un errore blocchi le query successive.
        try {
            return DB::transaction(fn () => $this->query($criteria, $municipality->id, $releaseId));
        } catch (QueryException $e) {
            report($e);

            throw new SearchException('Ricerca momentaneamente non disponibile. Riprova tra poco.', retry: true, previous: $e);
        }
    }

    // Validazione dei dati di input
    public static function validate(array $input): array
    {

        // Codice comunale
        $code = is_string($input['code'] ?? null) ? strtoupper(trim($input['code'])) : '';
        if ($code === '') {
            throw new SearchException('Seleziona un Comune con archivio disponibile.');
        }

        // Percorso: private o business
        $segment = $input['segment'] ?? null;
        if (! in_array($segment, ['private', 'business'], true)) {
            throw new SearchException('Percorso non valido.');
        }

        // Tipo di abitazione: garage o altro
        $housing = $input['housing'] ?? null;
        $housing = $housing === '' ? null : $housing;
        if ($housing !== null && ($segment !== 'private' || $housing !== 'garage')) {
            throw new SearchException('Scegli abitazioni o box auto nel percorso Private.');
        }

        if ($segment === 'business') {
            $type = $input['businessType'] ?? 'all';
            if (! is_string($type) || ! isset(Categories::BUSINESS_TYPES[$type])) {
                throw new SearchException('Categoria non valida.');
            }
            $groups = Categories::BUSINESS_TYPES[$type]['groups'];
        } else {
            $groups = Categories::scopeGroups($segment, $housing) ?? [];
        }

        // Categorie catastali
        $categories = $input['categories'] ?? [];
        if (! is_array($categories) || count($categories) > 100) {
            throw new SearchException('Categoria catastale non valida.');
        }
        $selectable = Categories::selectable($groups);
        $categories = array_values(array_unique(array_map(function ($category) use ($selectable) {
            $normalized = is_string($category) ? Categories::normalize($category) : '';
            if ($normalized === '' || ! in_array($normalized, $selectable, true)) {
                throw new SearchException('Categoria catastale non valida.');
            }

            return $normalized;
        }, $categories)));

        // Superficie minima e massima
        $min = self::number($input['min'] ?? null);
        $max = self::number($input['max'] ?? null);
        if ($min !== null && $max !== null && $min > $max) {
            throw new SearchException('Il minimo non può superare il massimo.');
        }

        // Unità di misura
        $dimensions = array_unique($categories !== []
            ? array_map(Categories::dimensionUnit(...), $categories)
            : array_map(fn (string $group) => Categories::GROUPS[$group]['unit'], $groups));
        if (($min !== null || $max !== null) && (count($dimensions) !== 1 || in_array(null, $dimensions, true))) {
            throw new SearchException('Per un intervallo scegli solo categorie in vani, solo categorie in m² oppure solo categorie in m³.');
        }

        // Trova lib/required-vani.ts: in vani or m² both limits are mandatory and above zero.
        $measure = self::requiredMeasure($segment, $housing, $groups, $categories);
        if ($measure !== null) {
            if ($min === null || $max === null) {
                throw new SearchException("Indica entrambi i valori in {$measure}: Da e A.");
            }
            if ($min <= 0 || $max <= 0) {
                throw new SearchException("Inserisci valori maggiori di zero in {$measure}.");
            }
        }

        $text = [];
        foreach (['address', 'section', 'sheet', 'parcel'] as $key) {
            $value = $input[$key] ?? null;
            if ($value !== null && (! is_string($value) || mb_strlen($value) > 200)) {
                throw new SearchException('Filtro troppo lungo.');
            }
            $text[$key] = $value === null || trim($value) === '' ? null : self::cadastralId($value);
        }

        $circle = self::circle($input['circle'] ?? null);

        $sort = $input['sort'] ?? 'surface-desc';
        if (! in_array($sort, self::SORTS, true)) {
            throw new SearchException('Ordinamento non valido.');
        }
        if ($sort === 'distance' && $circle === null) {
            throw new SearchException('L\'ordinamento per distanza richiede un punto sulla mappa.');
        }

        $page = $input['page'] ?? 1;
        if (! is_int($page) || $page < 1) {
            throw new SearchException('Pagina non valida.');
        }

        $pageSize = $input['pageSize'] ?? 10;
        if (! in_array($pageSize, self::PAGE_SIZES, true)) {
            throw new SearchException('Numero di risultati per pagina non valido.');
        }

        return [
            'code' => $code,
            'segment' => $segment,
            'housing' => $housing,
            'groups' => $groups,
            'categories' => $categories,
            'min' => $min,
            'max' => $max,
            'address' => is_string($input['address'] ?? null) ? Street::normalize($input['address']) : '',
            'section' => $text['section'],
            'sheet' => $text['sheet'],
            'parcel' => $text['parcel'],
            'circle' => $circle,
            'sort' => $sort,
            'page' => $page,
            'pageSize' => $pageSize,
        ];
    }

    /**
     * One search circle: lat/lng WGS84 and radius in metres (step 50, default 500).
     *
     * @return array{lat: float, lng: float, radius: int}|null
     */
    public static function circle(mixed $input): ?array
    {
        if ($input === null || $input === '') {
            return null;
        }

        if (! is_array($input)) {
            throw new SearchException('Punto di ricerca non valido.');
        }

        $lat = $input['lat'] ?? null;
        $lng = $input['lng'] ?? null;
        if (! is_numeric($lat) || ! is_numeric($lng) || ! is_finite((float) $lat) || ! is_finite((float) $lng)) {
            throw new SearchException('Indica latitudine e longitudine del punto.');
        }
        $lat = (float) $lat;
        $lng = (float) $lng;
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            throw new SearchException('Coordinate fuori intervallo.');
        }

        $radius = $input['radius'] ?? self::RADIUS_DEFAULT;
        if (is_string($radius) && is_numeric($radius)) {
            $radius = (float) $radius;
        }
        if (is_float($radius) && floor($radius) === $radius) {
            $radius = (int) $radius;
        }
        if (! is_int($radius) || $radius < self::RADIUS_MIN || $radius > self::RADIUS_MAX || $radius % self::RADIUS_STEP !== 0) {
            throw new SearchException('Il raggio deve essere tra 200 e 5000 metri, a passi di 50.');
        }

        return ['lat' => $lat, 'lng' => $lng, 'radius' => $radius];
    }

    // Normalizza l'ID catastale
    public static function cadastralId(string $value): string
    {
        return (string) preg_replace('/^0+(?=\d)/', '', strtoupper(trim($value)));
    }

    // Misura in cui un intervallo è obbligatorio (Trova lib/required-vani.ts catalogMeasure).
    // Null quando le categorie scelte mescolano misure o non ne hanno: allora l'intervallo è facoltativo.
    public static function requiredMeasure(string $segment, ?string $housing, array $groups, array $categories): ?string
    {
        if ($segment === 'private') {
            return $housing === 'garage' ? 'm²' : 'vani';
        }

        $units = array_values(array_unique($categories !== []
            ? array_map(Categories::dimensionUnit(...), $categories)
            : array_map(fn (string $group) => match (true) {
                in_array($group, ['A', 'A10'], true) => 'vani',
                (bool) preg_match('/^C[123467]$/', $group) => 'm²',
                default => null,
            }, $groups)));

        return count($units) === 1 && in_array($units[0], ['vani', 'm²'], true) ? $units[0] : null;
    }

    // Query dei risultati
    private function query(array $c, int $municipalityId, int $releaseId): CatalogSearchResult
    {
        $private = $c['segment'] === 'private';
        $garage = $private && $c['housing'] === 'garage';
        $scope = Categories::scopeGroups($c['segment'], $c['housing']) ?? [];
        $circle = $c['circle'];

        $conditions = [
            'p.municipality_id = ?',
            "v.status = 'eligible'",
            "v.category NOT LIKE 'B/%'",
            "v.category NOT LIKE 'E/%'",
            'v.search_group IN ('.self::placeholders($scope).')',
            'v.search_group IN ('.self::placeholders($c['groups']).')',
        ];
        $args = [$releaseId, $municipalityId, ...$scope, ...$c['groups']];

        if ($c['categories'] !== []) {
            $conditions[] = 'v.category IN ('.self::placeholders($c['categories']).')';
            array_push($args, ...$c['categories']);
        }

        foreach (['section' => 'p.section', 'sheet' => 'p.sheet', 'parcel' => 'p.number'] as $key => $column) {
            if ($c[$key] !== null) {
                $conditions[] = "{$column} = ?";
                $args[] = $c[$key];
            }
        }

        if ($c['address'] !== '') {
            $conditions[] = "position(' ' || ? || ' ' in ".Street::sql('v.address_raw').') > 0';
            $args[] = $c['address'];
        }

        if ($c['min'] !== null || $c['max'] !== null) {
            $expected = ($c['categories'][0] ?? $c['groups'][0])[0];
            $limits = ['v.consistency > 0', 'v.consistency_unit = ?'];
            $args[] = match ($expected) {
                'A' => 'vani',
                'B' => 'm³',
                default => 'm²',
            };
            if ($c['min'] !== null) {
                $limits[] = 'v.consistency >= ?';
                $args[] = $c['min'];
            }
            if ($c['max'] !== null) {
                $limits[] = 'v.consistency <= ?';
                $args[] = $c['max'];
            }
            // Business range in m² si applica solo al gruppo C, come in Trova.
            $conditions[] = $c['segment'] === 'business' && $expected !== 'A' && $expected !== 'B'
                ? "(v.category NOT LIKE 'C/%' OR (".implode(' AND ', $limits).'))'
                : '('.implode(' AND ', $limits).')';
        }

        // Circle: parcels without a search point are excluded (EXISTS, not LEFT JOIN).
        if ($circle !== null) {
            $conditions[] = 'EXISTS (
                SELECT 1 FROM parcel_search_points psp
                WHERE psp.parcel_id = p.id
                  AND psp.catalog_release_id = ?
                  AND ST_DWithin(
                      psp.location::geography,
                      ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography,
                      ?
                  )
            )';
            array_push($args, $releaseId, $circle['lng'], $circle['lat'], $circle['radius']);
        }

        // Risultati privati: mostra l'indirizzo senza il piano; Business mantiene l'indirizzo completo.
        $address = $private
            ? "trim(CASE WHEN position(' PIANO ' in upper(m.address_raw)) > 0 THEN substr(m.address_raw, 1, position(' PIANO ' in upper(m.address_raw)) - 1) ELSE m.address_raw END)"
            : 'm.address_raw';
        // Solo una misura è comparabile tra i parcel. Business non ha misure finché non esistono attività.
        $comparable = $private
            ? "CASE WHEN m.consistency_unit = '".($garage ? 'm²' : 'vani')."' THEN m.consistency END"
            : 'NULL::numeric';

        $tieBreak = "CASE WHEN trim(coalesce(address, '')) = '' THEN 1 ELSE 0 END, lower(address), section, sheet, number";

        $distanceSelect = $circle === null
            ? 'NULL::double precision AS distance_m'
            : '(SELECT ST_Distance(
                    psp.location::geography,
                    ST_SetSRID(ST_MakePoint('.((float) $circle['lng']).', '.((float) $circle['lat']).'), 4326)::geography
               )
               FROM parcel_search_points psp
               WHERE psp.parcel_id = m.parcel_id AND psp.catalog_release_id = '.((int) $releaseId).'
              ) AS distance_m';

        $order = match ($c['sort']) {
            'best' => 'records DESC, ',
            'surface-asc' => 'min_value IS NULL, min_value ASC, ',
            'surface-desc' => 'max_value IS NULL, max_value DESC, ',
            'distance' => 'distance_m ASC, ',
            default => '',
        }.$tieBreak;

        $sql = 'WITH matched AS MATERIALIZED (
                SELECT u.id unit_id, u.parcel_id, u.subalterno, p.section, p.sheet, p.number,
                    v.category, v.consistency, v.consistency_unit, v.address_raw
                FROM cadastral_unit_versions v
                JOIN cadastral_units u ON u.id = v.cadastral_unit_id
                JOIN parcels p ON p.id = u.parcel_id
                WHERE v.catalog_release_id = ? AND '.implode(' AND ', $conditions).'
            ), selection AS MATERIALIZED (
                SELECT m.parcel_id, m.section, m.sheet, m.number,
                    min(nullif('.$address.", '')) address,
                    min({$comparable}) min_value, max({$comparable}) max_value,
                    count(*) records, count(m.subalterno) identified,
                    array_to_json(array_agg(DISTINCT m.category ORDER BY m.category)) categories,
                    {$distanceSelect}
                FROM matched m GROUP BY m.parcel_id, m.section, m.sheet, m.number
            ), page AS (
                SELECT * FROM selection ORDER BY {$order} LIMIT ? OFFSET ?
            )
            SELECT
                (SELECT count(*) FROM selection) total,
                (SELECT count(*) FROM matched) matched_units,
                (SELECT coalesce(json_agg(r ORDER BY r.position), '[]') FROM (
                    SELECT row_number() OVER (ORDER BY {$order}) position, pg.*,
                        ST_Y(sp.location) latitude, ST_X(sp.location) longitude,
                        (SELECT ST_AsGeoJSON(ST_Multi(ST_Union(bv.footprint)))::json
                         FROM building_parcel_links l
                         JOIN building_versions bv ON bv.building_id = l.building_id AND bv.catalog_release_id = l.catalog_release_id
                         WHERE l.parcel_id = pg.parcel_id AND l.catalog_release_id = ?) footprint,
                        (SELECT json_agg(json_build_object(
                                'sub', m.subalterno, 'category', m.category, 'value', m.consistency,
                                'measure', m.consistency_unit, 'address', m.address_raw)
                            ORDER BY coalesce(substring(m.subalterno from '^\\d+')::int, 0), m.subalterno, m.unit_id)
                         FROM matched m WHERE m.parcel_id = pg.parcel_id) units
                    FROM page pg
                    LEFT JOIN parcel_search_points sp ON sp.parcel_id = pg.parcel_id AND sp.catalog_release_id = ?
                ) r) rows";

        $offset = ($c['page'] - 1) * $c['pageSize'];
        $result = DB::selectOne($sql, [...$args, $c['pageSize'], $offset, $releaseId, $releaseId]);

        return new CatalogSearchResult(
            total: (int) $result->total,
            matchedUnits: (int) $result->matched_units,
            page: $c['page'],
            pageSize: $c['pageSize'],
            measure: $private ? ($garage ? 'm²' : 'vani') : null,
            rows: json_decode($result->rows, true),
        );
    }

    // Converte il valore in un numero
    private static function number(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value < 0) {
            throw new SearchException('Inserisci un intervallo positivo.');
        }

        return (float) $value;
    }

    // Genera i placeholder per la query SQL
    private static function placeholders(array $values): string
    {
        return $values === [] ? 'NULL' : implode(', ', array_fill(0, count($values), '?'));
    }
}
