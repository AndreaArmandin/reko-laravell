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

    /**
     * Esegue la ricerca.
     *
     * @param  array<string, mixed>  $input
     */
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

    /**
     * Validazione dei dati di input, con i messaggi di Trova.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
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

        // Tipo di abitazione: tutte, appartamento, casa indipendente (classificazione v4) o box auto
        $housing = $input['housing'] ?? null;
        $housing = $housing === '' ? null : $housing;
        if ($housing !== null && ($segment !== 'private' || ! in_array($housing, ['garage', 'apartment', 'independent'], true))) {
            throw new SearchException('Scegli abitazioni o box auto nel percorso Private.');
        }

        // Attività Trova (lib/crm/business-activities.ts): il server decide categorie e gruppi
        $activity = $input['activity'] ?? null;
        $activity = $activity === '' ? null : $activity;
        $related = $input['includeRelated'] ?? false;
        if ($related !== false && ($related !== true || $activity === null)) {
            throw new SearchException('Scegli un’attività prima di ampliare la ricerca.');
        }
        if ($activity !== null && ($segment !== 'business' || BusinessActivities::find($activity) === null)) {
            throw new SearchException('Scegli un’attività valida nel percorso Business.');
        }

        if ($activity !== null) {
            $activityCategories = BusinessActivities::categories($activity, $related);
            $groups = array_values(array_unique(array_map(Categories::group(...), $activityCategories)));
            if (! array_key_exists('categories', $input)) {
                $input['categories'] = $activityCategories;
            } elseif (! is_array($input['categories']) || array_filter($input['categories'], fn ($category) => ! is_string($category) || ! in_array(Categories::normalize($category), $activityCategories, true),
            ) !== []) {
                throw new SearchException('La categoria scelta non è prevista per questa attività.');
            }
        } elseif ($segment === 'business') {
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
        $rule = BusinessActivities::find($activity);
        $dimensions = array_unique(match (true) {
            $rule !== null => [$rule['measure'] !== '' ? $rule['measure'] : null],
            $categories !== [] => array_map(Categories::dimensionUnit(...), $categories),
            default => array_map(fn (string $group) => Categories::GROUPS[$group]['unit'], $groups),
        });
        if (($min !== null || $max !== null) && (count($dimensions) !== 1 || in_array(null, $dimensions, true))) {
            throw new SearchException('Per un intervallo scegli solo categorie in vani, solo categorie in m² oppure solo categorie in m³.');
        }

        // Trova lib/required-vani.ts: in vani or m² both limits are mandatory and above zero.
        $measure = $rule !== null
            ? (in_array($rule['measure'], ['vani', 'm²'], true) ? $rule['measure'] : null)
            : self::requiredMeasure($segment, $housing, $groups, $categories);
        if ($measure !== null) {
            if ($min === null || $max === null) {
                throw new SearchException("Indica entrambi i valori in {$measure}: Da e A.");
            }
            if ($min <= 0 || $max <= 0) {
                throw new SearchException("Inserisci valori maggiori di zero in {$measure}.");
            }
        }

        // Piani (Trova lib/crm/cadastral-search.ts): intervallo o ultimo piano solo per gli appartamenti
        $apartment = $segment === 'private' && $housing === 'apartment';
        $floors = [];
        foreach (['floorMin', 'floorMax'] as $key) {
            $floor = $input[$key] ?? null;
            if ($floor !== null && (! is_int($floor) || $floor < -9 || $floor > 49 || ! $apartment)) {
                throw new SearchException('Scegli un piano documentato nel percorso Private · Appartamento.');
            }
            $floors[$key] = $floor;
        }
        if ($floors['floorMin'] !== null && $floors['floorMax'] !== null && $floors['floorMin'] > $floors['floorMax']) {
            throw new SearchException('Il piano minimo non può superare il piano massimo.');
        }
        $topFloor = $input['topFloor'] ?? false;
        if ($topFloor !== false && ($topFloor !== true || ! $apartment)) {
            throw new SearchException('Ultimo piano è disponibile per gli appartamenti Private.');
        }
        if ($topFloor && ($floors['floorMin'] !== null || $floors['floorMax'] !== null)) {
            throw new SearchException('Scegli Ultimo piano oppure un intervallo, non entrambi.');
        }
        $exactFloor = $input['exactFloor'] ?? null;
        if ($exactFloor !== null && (! ($segment === 'private' && $housing === 'garage') || ! is_int($exactFloor) || $exactFloor < -9 || $exactFloor > 49)) {
            throw new SearchException('Scegli un piano disponibile per il box o il singolo spazio.');
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
        $zoneId = $input['zoneId'] ?? null;
        if ($zoneId === '' || $zoneId === null) {
            $zoneId = null;
        } elseif (is_string($zoneId) && ctype_digit($zoneId)) {
            $zoneId = (int) $zoneId;
        }
        if ($zoneId !== null && (! is_int($zoneId) || $zoneId < 1)) {
            throw new SearchException('Zona non valida.');
        }
        $polygon = self::polygon($input['polygon'] ?? null);
        if (count(array_filter([$circle, $zoneId, $polygon], fn ($value) => $value !== null)) > 1) {
            throw new SearchException('Scegli un solo metodo per delimitare la zona.');
        }

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
            'activity' => $activity,
            'floorMin' => $floors['floorMin'],
            'floorMax' => $floors['floorMax'],
            'topFloor' => $topFloor,
            'exactFloor' => $exactFloor,
            'groups' => $groups,
            'categories' => $categories,
            'min' => $min,
            'max' => $max,
            'address' => is_string($input['address'] ?? null) ? Street::normalize($input['address']) : '',
            'section' => $text['section'],
            'sheet' => $text['sheet'],
            'parcel' => $text['parcel'],
            'circle' => $circle,
            'zoneId' => $zoneId,
            'polygon' => $polygon,
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

    /**
     * Convert a list of [longitude, latitude] points into a closed GeoJSON polygon.
     */
    private static function polygon(mixed $input): ?string
    {
        if ($input === null || $input === []) {
            return null;
        }

        // Stesse regole di Trova (zone-boundary.ts): 3–64 vertici distinti, lati che non si incrociano, un'area vera
        $points = ZoneBoundary::validate($input);
        $points[] = $points[0];

        return json_encode([
            'type' => 'Polygon',
            'coordinates' => [$points],
        ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    // Normalizza l'ID catastale
    public static function cadastralId(string $value): string
    {
        return (string) preg_replace('/^0+(?=\d)/', '', strtoupper(trim($value)));
    }

    // Misura in cui un intervallo è obbligatorio (Trova lib/required-vani.ts catalogMeasure).
    // Null quando le categorie scelte mescolano misure o non ne hanno: allora l'intervallo è facoltativo.
    /**
     * @param  list<string>  $groups
     * @param  list<string>  $categories
     */
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

    /**
     * Query dei risultati.
     *
     * @param  array<string, mixed>  $c  criteri validati
     */
    private function query(array $c, int $municipalityId, int $releaseId): CatalogSearchResult
    {
        $private = $c['segment'] === 'private';
        $scope = Categories::scopeGroups($c['segment'], $c['housing']) ?? [];
        $circle = $c['circle'];

        if ($c['zoneId'] !== null && ! DB::table('geographic_zones')
            ->where('municipality_id', $municipalityId)->where('id', $c['zoneId'])->exists()) {
            throw new SearchException('La zona selezionata non è disponibile per questo Comune.');
        }

        if ($c['polygon'] !== null) {
            $validity = DB::selectOne(
                'SELECT ST_IsValid(g) AS valid, ST_Area(g::geography) AS area FROM (SELECT ST_SetSRID(ST_GeomFromGeoJSON(?), 4326) AS g) polygon',
                [$c['polygon']],
            );
            if (! $validity->valid || (float) $validity->area < 0.01 || (float) $validity->area > 100000000000) {
                throw new SearchException('Il perimetro disegnato non è valido. Modifica i punti sulla mappa.');
            }
        }

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

        // Classificazione v4 precalcolata in trova_unit_facts (Trova housing-v4 / apartment-eligibility)
        if ($c['housing'] === 'apartment') {
            $conditions[] = "f.housing_outcome IN ('APPARTAMENTO', 'DA VERIFICARE')";
            $conditions[] = 'v.category NOT IN ('.self::placeholders(HousingV4::APARTMENT_EXCLUDED_CATEGORIES).')';
            $conditions[] = 'f.apartment_parcel';
            array_push($args, ...HousingV4::APARTMENT_EXCLUDED_CATEGORIES);
        }
        if ($c['housing'] === 'independent') {
            $conditions[] = "f.housing_outcome = 'CASA INDIPENDENTE' AND f.vertical_independent";
        }
        // Almeno un livello dell'unità nell'intervallo di piani
        if ($c['floorMin'] !== null || $c['floorMax'] !== null) {
            $conditions[] = 'EXISTS (SELECT 1 FROM unnest(f.v4_levels) level WHERE level BETWEEN ? AND ?)';
            array_push($args, $c['floorMin'] ?? -9, $c['floorMax'] ?? 49);
        }
        // Piano abitativo più alto documentato nella particella
        if ($c['topFloor']) {
            $conditions[] = 'f.parcel_top_floor >= 0 AND f.v4_highest = f.parcel_top_floor';
        }
        // Box su un solo piano, esattamente quello scelto
        if ($c['exactFloor'] !== null) {
            $conditions[] = 'f.floor_levels = ARRAY[?]::smallint[]';
            $args[] = $c['exactFloor'];
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

        if ($c['zoneId'] !== null) {
            $conditions[] = 'EXISTS (
                SELECT 1 FROM geographic_zones z
                JOIN parcel_search_points psp ON psp.catalog_release_id = ? AND psp.parcel_id = p.id
                WHERE z.id = ? AND z.municipality_id = ?
                  AND ST_Covers(z.boundary, psp.location)
            )';
            array_push($args, $releaseId, $c['zoneId'], $municipalityId);
        }

        if ($c['polygon'] !== null) {
            $conditions[] = 'EXISTS (
                SELECT 1 FROM parcel_search_points psp
                WHERE psp.parcel_id = p.id
                  AND psp.catalog_release_id = ?
                  AND ST_Covers(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326), psp.location)
            )';
            array_push($args, $releaseId, $c['polygon']);
        }

        // Risultati privati: mostra l'indirizzo senza il piano; Business mantiene l'indirizzo completo.
        $address = $private
            ? "trim(CASE WHEN position(' PIANO ' in upper(m.address_raw)) > 0 THEN substr(m.address_raw, 1, position(' PIANO ' in upper(m.address_raw)) - 1) ELSE m.address_raw END)"
            : 'm.address_raw';
        // Solo una misura è comparabile tra le particelle: vani, m² oppure nessuna (Business senza misura)
        $measure = self::measure($c);
        $comparable = $measure !== null
            ? "CASE WHEN m.consistency_unit = '{$measure}' THEN m.consistency END"
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
                    v.category, v.consistency, v.consistency_unit, v.address_raw, f.v4_levels,
                    f.housing_outcome, f.housing_confidence, f.housing_levels, f.housing_notes
                FROM cadastral_unit_versions v
                JOIN cadastral_units u ON u.id = v.cadastral_unit_id
                JOIN parcels p ON p.id = u.parcel_id
                LEFT JOIN trova_unit_facts f ON f.cadastral_unit_id = u.id AND f.catalog_release_id = v.catalog_release_id
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
                                'measure', m.consistency_unit, 'address', m.address_raw, 'levels', to_json(m.v4_levels),
                                'housing', CASE WHEN m.housing_outcome IS NOT NULL THEN json_build_object(
                                    'esito', m.housing_outcome, 'conf', m.housing_confidence,
                                    'livelli_txt', m.housing_levels, 'note', m.housing_notes) END)
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
            measure: $measure,
            rows: json_decode($result->rows, true),
        );
    }

    /**
     * Misura confrontabile della ricerca (Trova required-vani.ts catalogMeasure): 'vani', 'm²' o null.
     *
     * @param  array<string, mixed>  $c
     */
    private static function measure(array $c): ?string
    {
        if ($c['segment'] === 'private') {
            return $c['housing'] === 'garage' ? 'm²' : 'vani';
        }
        if ($c['activity'] !== null) {
            $measure = BusinessActivities::ALL[$c['activity']]['measure'];

            return $measure !== '' ? $measure : null;
        }

        return self::requiredMeasure($c['segment'], null, $c['groups'], $c['categories']);
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

    /**
     * Genera i placeholder per la query SQL.
     *
     * @param  array<mixed>  $values
     */
    private static function placeholders(array $values): string
    {
        return $values === [] ? 'NULL' : implode(', ', array_fill(0, count($values), '?'));
    }
}
