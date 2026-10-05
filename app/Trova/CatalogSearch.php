<?php

namespace App\Trova;

use App\Models\Municipality;
use Illuminate\Support\Facades\DB;

/**
 * Catalogue search ported from Trova (lib/crm/cadastral-search.ts searchCadastral
 * validation and lib/crm/catalog-query.ts queryCatalogPage), first slice:
 * municipality, Private (homes, garages) and Business types, categories,
 * consistency range, address/section/sheet/parcel filters, sorting and pages.
 * Results are parcels, as on Trova's public pages; matching units are nested.
 *
 * Not ported yet: housing classification (apartment, independent house, floors,
 * top floor), which in Trova also limits Private homes to parcels with a housing
 * context; circle, bounds and zones; business activities and networks; civic numbers.
 */
final class CatalogSearch
{
    public const SORTS = ['best', 'address', 'surface-asc', 'surface-desc'];

    public const PAGE_SIZES = [10, 20];

    /**
     * @param  array{code?: mixed, segment?: mixed, housing?: mixed, businessType?: mixed, categories?: mixed,
     *     min?: mixed, max?: mixed, address?: mixed, section?: mixed, sheet?: mixed, parcel?: mixed,
     *     sort?: mixed, page?: mixed, pageSize?: mixed}  $input
     */
    public function search(array $input): CatalogSearchResult
    {
        $criteria = self::validate($input);

        $municipality = Municipality::query()->where('cadastral_code', $criteria['code'])->with('catalog')->first();
        $releaseId = $municipality?->catalog?->catalog_release_id;

        if ($municipality === null || $releaseId === null) {
            throw new SearchException('Seleziona un Comune con archivio disponibile.');
        }

        return $this->query($criteria, $municipality->id, $releaseId);
    }

    /**
     * Same rules and messages as Trova's searchCadastral, for the ported options.
     *
     * @param  array<string, mixed>  $input
     * @return array{code: string, segment: string, housing: string|null, groups: list<string>, categories: list<string>,
     *     min: float|null, max: float|null, address: string, section: string|null, sheet: string|null, parcel: string|null,
     *     sort: string, page: int, pageSize: int}
     */
    public static function validate(array $input): array
    {
        $code = is_string($input['code'] ?? null) ? strtoupper(trim($input['code'])) : '';
        if ($code === '') {
            throw new SearchException('Seleziona un Comune con archivio disponibile.');
        }

        $segment = $input['segment'] ?? null;
        if (! in_array($segment, ['private', 'business'], true)) {
            throw new SearchException('Percorso non valido.');
        }

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

        $min = self::number($input['min'] ?? null);
        $max = self::number($input['max'] ?? null);
        if ($min !== null && $max !== null && $min > $max) {
            throw new SearchException('Il minimo non può superare il massimo.');
        }

        $dimensions = array_unique($categories !== []
            ? array_map(Categories::dimensionUnit(...), $categories)
            : array_map(fn (string $group) => Categories::GROUPS[$group]['unit'], $groups));
        if (($min !== null || $max !== null) && (count($dimensions) !== 1 || in_array(null, $dimensions, true))) {
            throw new SearchException('Per un intervallo scegli solo categorie in vani, solo categorie in m² oppure solo categorie in m³.');
        }

        $text = [];
        foreach (['address', 'section', 'sheet', 'parcel'] as $key) {
            $value = $input[$key] ?? null;
            if ($value !== null && (! is_string($value) || mb_strlen($value) > 200)) {
                throw new SearchException('Filtro troppo lungo.');
            }
            $text[$key] = $value === null || trim($value) === '' ? null : self::cadastralId($value);
        }

        $sort = $input['sort'] ?? 'surface-desc';
        if (! in_array($sort, self::SORTS, true)) {
            throw new SearchException('Ordinamento non valido.');
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
            'sort' => $sort,
            'page' => $page,
            'pageSize' => $pageSize,
        ];
    }

    /**
     * Trova's cadastralId: upper case, without leading zeros before a digit.
     */
    public static function cadastralId(string $value): string
    {
        return (string) preg_replace('/^0+(?=\d)/', '', strtoupper(trim($value)));
    }

    /**
     * @param  array{code: string, segment: string, housing: string|null, groups: list<string>, categories: list<string>,
     *     min: float|null, max: float|null, address: string, section: string|null, sheet: string|null, parcel: string|null,
     *     sort: string, page: int, pageSize: int}  $c
     */
    private function query(array $c, int $municipalityId, int $releaseId): CatalogSearchResult
    {
        $private = $c['segment'] === 'private';
        $garage = $private && $c['housing'] === 'garage';
        $scope = Categories::scopeGroups($c['segment'], $c['housing']) ?? [];

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
            // Business ranges in m² apply to group C only, as in Trova.
            $conditions[] = $c['segment'] === 'business' && $expected !== 'A' && $expected !== 'B'
                ? "(v.category NOT LIKE 'C/%' OR (".implode(' AND ', $limits).'))'
                : '('.implode(' AND ', $limits).')';
        }

        // Private results show the street without the floor; Business keeps the full address.
        $address = $private
            ? "trim(CASE WHEN position(' PIANO ' in upper(m.address_raw)) > 0 THEN substr(m.address_raw, 1, position(' PIANO ' in upper(m.address_raw)) - 1) ELSE m.address_raw END)"
            : 'm.address_raw';
        // Only one measure is comparable across a parcel. Business has none until activities exist.
        $comparable = $private
            ? "CASE WHEN m.consistency_unit = '".($garage ? 'm²' : 'vani')."' THEN m.consistency END"
            : 'NULL::numeric';

        $order = match ($c['sort']) {
            'best' => 'records DESC, ',
            'surface-asc' => 'min_value IS NULL, min_value ASC, ',
            'surface-desc' => 'max_value IS NULL, max_value DESC, ',
            default => '',
        }."CASE WHEN trim(coalesce(address, '')) = '' THEN 1 ELSE 0 END, lower(address), section, sheet, number";

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
                    array_to_json(array_agg(DISTINCT m.category ORDER BY m.category)) categories
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
                        (SELECT json_agg(json_build_object(
                                'sub', m.subalterno, 'category', m.category, 'value', m.consistency,
                                'measure', m.consistency_unit, 'address', m.address_raw)
                            ORDER BY coalesce(substring(m.subalterno from '^\\d+')::int, 0), m.subalterno, m.unit_id)
                         FROM matched m WHERE m.parcel_id = pg.parcel_id) units
                    FROM page pg
                    LEFT JOIN parcel_search_points sp ON sp.parcel_id = pg.parcel_id AND sp.catalog_release_id = ?
                ) r) rows";

        $offset = ($c['page'] - 1) * $c['pageSize'];
        $result = DB::selectOne($sql, [...$args, $c['pageSize'], $offset, $releaseId]);

        return new CatalogSearchResult(
            total: (int) $result->total,
            matchedUnits: (int) $result->matched_units,
            page: $c['page'],
            pageSize: $c['pageSize'],
            measure: $private ? ($garage ? 'm²' : 'vani') : null,
            rows: json_decode($result->rows, true),
        );
    }

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
     * @param  list<mixed>  $values
     */
    private static function placeholders(array $values): string
    {
        return $values === [] ? 'NULL' : implode(', ', array_fill(0, count($values), '?'));
    }
}
