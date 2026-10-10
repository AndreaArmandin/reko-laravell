<?php

namespace App\Gestionale\Scouting;

use App\Models\AgencyMembership;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Data of the "Mappa e zone" page: Comuni with cartography, census parcels visible to the operator,
 * cartographic outlines of the viewport, REKO neighborhoods. Geometry leaves this class as
 * GeoJSON ([lng, lat], for the map) or as [lat, lng] rings (the gestionale DemoPoint order).
 */
final class ScoutingMap
{
    public const MAX_PARCELS = 3000;

    public const MAX_OUTLINES = 800;

    /**
     * Comuni with an active catalog edition: the "inventories" of the original (municipalCartography).
     * Bounds [minLat, minLng, maxLat, maxLng] are the extent of the loaded parcel shapes: the coverage, not the administrative border.
     *
     * @return list<array{id: int, code: string, municipality: string, province: string, bounds: array<int, float>|null, sheets: int, parcels: int}>
     */
    public static function municipalities(): array
    {
        $rows = DB::table('municipalities as m')->join('municipality_catalogs as mc', 'mc.municipality_id', '=', 'm.id')
            ->leftJoin('territorial_provinces as tp', 'tp.id', '=', 'm.territorial_province_id')
            ->orderBy('m.name')->get(['m.id', 'm.cadastral_code', 'm.name', 'tp.abbreviation', 'mc.catalog_release_id']);
        $result = [];
        foreach ($rows as $row) {
            $coverage = Cache::remember('scouting.coverage.'.$row->catalog_release_id, 300, function () use ($row) {
                $e = DB::selectOne('SELECT ST_XMin(e) AS x0, ST_YMin(e) AS y0, ST_XMax(e) AS x1, ST_YMax(e) AS y1, n FROM (SELECT ST_Extent(boundary) AS e, count(boundary) AS n FROM parcel_versions WHERE catalog_release_id = ?) t', [$row->catalog_release_id]);

                return ['bounds' => $e->x0 === null ? null : [(float) $e->y0, (float) $e->x0, (float) $e->y1, (float) $e->x1], 'parcels' => (int) $e->n];
            });
            $result[] = ['id' => (int) $row->id, 'code' => $row->cadastral_code, 'municipality' => $row->name, 'province' => (string) $row->abbreviation,
                'bounds' => $coverage['bounds'], 'sheets' => 0, 'parcels' => $coverage['parcels']];
        }

        return $result;
    }

    /** @return list<array{id: int, name: string, notice: string|null}> */
    public static function neighborhoods(string $code): array
    {
        return DB::table('geographic_zones as z')->join('municipalities as m', 'm.id', '=', 'z.municipality_id')->where('m.cadastral_code', $code)
            ->orderBy('z.name')->get(['z.id', 'z.name', 'z.notice'])->map(fn ($z) => ['id' => (int) $z->id, 'name' => $z->name, 'notice' => $z->notice])->all();
    }

    /** GeoJSON features ([lng, lat]) of the neighborhoods of a Comune, for the picker. */
    public static function neighborhoodShapes(string $code, ?int $only = null): array
    {
        $rows = DB::table('geographic_zones as z')->join('municipalities as m', 'm.id', '=', 'z.municipality_id')->where('m.cadastral_code', $code)
            ->when($only !== null, fn ($q) => $q->where('z.id', $only))->whereNotNull('z.boundary')->orderBy('z.name')
            ->selectRaw('z.id, z.name, ST_AsGeoJSON(z.boundary, 6) AS g')->get();

        return ['type' => 'FeatureCollection', 'features' => $rows->map(fn ($z) => ['type' => 'Feature', 'properties' => ['id' => (int) $z->id, 'name' => $z->name], 'geometry' => json_decode($z->g, true)])->all()];
    }

    /** Whether a neighborhood belongs to the Comune (publicNeighborhood). */
    public static function neighborhoodExists(string $code, int $id): bool
    {
        return DB::table('geographic_zones as z')->join('municipalities as m', 'm.id', '=', 'z.municipality_id')->where('m.cadastral_code', $code)->where('z.id', $id)->exists();
    }

    /** Municipalities with a catalog (catalogMunicipality()): the Comune can be drawn on. */
    public static function hasCatalog(string $code): bool
    {
        return DB::table('municipalities as m')->join('municipality_catalogs as mc', 'mc.municipality_id', '=', 'm.id')->where('m.cadastral_code', strtoupper($code))->exists();
    }

    /**
     * SQL snippet: the parcels of the agency census that the operator can open.
     * Admin: every parcel with an assignment, an ownership or an activity of the agency. Scout: the parcels assigned to them.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private static function censusScope(AgencyMembership $actor): array
    {
        $units = '(SELECT cu.id FROM cadastral_units cu WHERE cu.parcel_id = p.id)';
        if ($actor->role === 'admin') {
            return ["(EXISTS (SELECT 1 FROM scouting_assignments sa WHERE sa.agency_id = ? AND (sa.parcel_id = p.id OR sa.cadastral_unit_id IN {$units}))
                OR EXISTS (SELECT 1 FROM agency_unit_observations auo JOIN cadastral_units cuo ON cuo.id = auo.cadastral_unit_id WHERE auo.agency_id = ? AND cuo.parcel_id = p.id)
                OR EXISTS (SELECT 1 FROM ownerships o WHERE o.agency_id = ? AND (o.parcel_id = p.id OR o.cadastral_unit_id IN {$units}))
                OR EXISTS (SELECT 1 FROM activities ac WHERE ac.agency_id = ? AND ac.parcel_id = p.id))", [$actor->agency_id, $actor->agency_id, $actor->agency_id, $actor->agency_id]];
        }

        return ["EXISTS (SELECT 1 FROM scouting_assignments sa WHERE sa.agency_id = ? AND sa.user_id = ? AND (sa.parcel_id = p.id OR sa.cadastral_unit_id IN {$units}))", [$actor->agency_id, $actor->user_id]];
    }

    /**
     * Census parcels with a loaded shape, in a Comune. Filters: zoneId (point of the parcel inside the zone boundary),
     * publicZoneId (point inside the REKO neighborhood), street (raw address), outcome (last outcome or "Nessun esito").
     *
     * @param  array{zoneId?: int|null, publicZoneId?: int|null, street?: string|null, outcome?: string|null}  $filters
     * @return array{parcels: list<array<string, mixed>>, addresses: list<string>, missing: int, accessible: int}
     */
    public static function parcels(AgencyMembership $actor, string $code, array $filters = []): array
    {
        [$scope, $scopeArgs] = self::censusScope($actor);
        $address = 'SELECT v.address_raw FROM cadastral_units cu JOIN cadastral_unit_versions v ON v.cadastral_unit_id = cu.id AND v.catalog_release_id = mc.catalog_release_id WHERE cu.parcel_id = p.id AND v.address_raw IS NOT NULL AND btrim(v.address_raw) <> \'\' ORDER BY cu.id LIMIT 1';
        $base = "FROM parcels p JOIN municipalities m ON m.id = p.municipality_id AND m.cadastral_code = ?
            JOIN municipality_catalogs mc ON mc.municipality_id = m.id
            LEFT JOIN parcel_versions pv ON pv.parcel_id = p.id AND pv.catalog_release_id = mc.catalog_release_id
            WHERE {$scope}";
        $rows = DB::select("SELECT p.id, p.section, p.sheet, p.number, p.cadastral_kind, m.cadastral_code AS code, mc.catalog_release_id AS release_id, ({$address}) AS address,
                ST_AsGeoJSON(pv.boundary, 6) AS g, ST_Y(ST_Centroid(ST_Envelope(pv.boundary))) AS lat, ST_X(ST_Centroid(ST_Envelope(pv.boundary))) AS lng,
                CASE WHEN pv.boundary IS NULL THEN NULL ELSE COALESCE((SELECT psp.location FROM parcel_search_points psp WHERE psp.parcel_id = p.id AND psp.catalog_release_id = mc.catalog_release_id), ST_PointOnSurface(pv.boundary)) END AS anchor
            {$base} ORDER BY p.id LIMIT ".self::MAX_PARCELS, [strtoupper($code), ...$scopeArgs]);

        $zoneBoundary = isset($filters['zoneId']) ? DB::selectOne('SELECT ST_AsGeoJSON(boundary) AS g FROM scouting_zones WHERE agency_id = ? AND id = ? AND boundary IS NOT NULL', [$actor->agency_id, $filters['zoneId']]) : null;
        $publicZone = isset($filters['publicZoneId']) ? DB::selectOne('SELECT ST_AsGeoJSON(boundary) AS g FROM geographic_zones WHERE id = ? AND boundary IS NOT NULL', [$filters['publicZoneId']]) : null;

        $withShape = array_values(array_filter($rows, fn ($r) => $r->g !== null));
        $ids = array_map(fn ($r) => (int) $r->id, $withShape);
        $last = self::lastOutcomes($actor, $ids);
        $inside = fn (?string $geojson, array $ids) => $geojson === null || $ids === [] ? array_flip($ids) : self::covered($geojson, $ids);
        $inZone = $inside($zoneBoundary?->g, $ids);
        $inPublic = $inside($publicZone?->g, $ids);

        $accessible = array_values(array_filter($rows, fn ($r) => true));
        $out = [];
        foreach ($withShape as $r) {
            $id = (int) $r->id;
            if (($filters['street'] ?? null) !== null && $filters['street'] !== '' && $filters['street'] !== 'Tutte' && $r->address !== $filters['street']) {
                continue;
            }
            $outcome = $last[$id] ?? 'Nessun esito';
            if (($filters['outcome'] ?? null) !== null && $filters['outcome'] !== 'Tutti' && $outcome !== $filters['outcome']) {
                continue;
            }
            if (! isset($inZone[$id]) || ! isset($inPublic[$id])) {
                continue;
            }
            $out[] = ['id' => $id, 'code' => $r->code, 'section' => (string) $r->section, 'sheet' => (string) $r->sheet, 'parcel' => (string) $r->number, 'kind' => $r->cadastral_kind,
                'address' => $r->address, 'geometry' => json_decode($r->g, true), 'point' => [(float) $r->lat, (float) $r->lng], 'outcome' => $outcome, 'release_id' => (int) $r->release_id];
        }

        return [
            'parcels' => $out,
            'addresses' => array_values(array_unique(array_filter(array_map(fn ($r) => $r->address, $accessible)))),
            'missing' => count($accessible) - count($withShape),
            'accessible' => count($accessible),
        ];
    }

    /**
     * Parcels (ids) whose point lies inside a GeoJSON area. Same point as the shape filter of Trova:
     * the search point of the parcel, else a point inside its shape.
     *
     * @param  list<int>  $ids
     * @return array<int, int> id => id
     */
    private static function covered(string $areaGeoJson, array $ids): array
    {
        $in = implode(',', $ids);
        $rows = DB::select("SELECT p.id FROM parcels p JOIN municipality_catalogs mc ON mc.municipality_id = p.municipality_id
            JOIN parcel_versions pv ON pv.parcel_id = p.id AND pv.catalog_release_id = mc.catalog_release_id
            WHERE p.id IN ({$in}) AND ST_Covers(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326), COALESCE((SELECT psp.location FROM parcel_search_points psp WHERE psp.parcel_id = p.id AND psp.catalog_release_id = mc.catalog_release_id), ST_PointOnSurface(pv.boundary)))", [$areaGeoJson]);

        return collect($rows)->mapWithKeys(fn ($r) => [(int) $r->id => (int) $r->id])->all();
    }

    /**
     * last(): outcome of the latest completed activity with an outcome, per parcel. Activities visible to the operator.
     *
     * @param  list<int>  $parcelIds
     * @return array<int, string>
     */
    public static function lastOutcomes(AgencyMembership $actor, array $parcelIds): array
    {
        if ($parcelIds === []) {
            return [];
        }
        $rows = self::activities($actor)->whereIn('a.parcel_id', $parcelIds)
            ->orderByRaw('COALESCE(a.completed_at, a.created_at) DESC, a.id DESC')->get(['a.parcel_id', 'a.outcome']);
        $last = [];
        foreach ($rows as $row) {
            $last[$row->parcel_id] ??= $row->outcome;
        }

        return $last;
    }

    /** Outcomes of the completed activities visible to the operator (filter "Esito"). @return list<string> */
    public static function outcomes(AgencyMembership $actor): array
    {
        return self::activities($actor)->distinct()->orderBy('a.outcome')->pluck('a.outcome')->map(fn ($o) => (string) $o)->all();
    }

    private static function activities(AgencyMembership $actor)
    {
        return DB::table('activities as a')->where('a.agency_id', $actor->agency_id)->where('a.status', 'Completata')->whereNotNull('a.outcome')->where('a.outcome', '<>', '')
            ->when($actor->role !== 'admin', fn ($q) => $q->where(fn ($w) => $w->where('a.assigned_to_user_id', $actor->user_id)->orWhere('a.created_by_user_id', $actor->user_id)->orWhere('a.user_id', $actor->user_id)));
    }

    /**
     * Operator assigned to each parcel (parcelAgents): the parcel-level assignment, else the latest assignment of one of its units.
     *
     * @param  list<int>  $parcelIds
     * @return array<int, int> parcel id => user id
     */
    public static function parcelAgents(int $agencyId, array $parcelIds): array
    {
        if ($parcelIds === []) {
            return [];
        }
        $in = implode(',', array_map('intval', $parcelIds));
        $rows = DB::select("SELECT DISTINCT ON (parcel_id) parcel_id, user_id FROM (
                SELECT sa.parcel_id AS parcel_id, sa.user_id, 0 AS rank, sa.id FROM scouting_assignments sa WHERE sa.agency_id = ? AND sa.cadastral_unit_id IS NULL AND sa.parcel_id IN ({$in})
                UNION ALL
                SELECT cu.parcel_id, sa.user_id, 1 AS rank, sa.id FROM scouting_assignments sa JOIN cadastral_units cu ON cu.id = sa.cadastral_unit_id WHERE sa.agency_id = ? AND cu.parcel_id IN ({$in})
            ) t ORDER BY parcel_id, rank, id DESC", [$agencyId, $agencyId]);

        return collect($rows)->mapWithKeys(fn ($r) => [(int) $r->parcel_id => (int) $r->user_id])->all();
    }

    /**
     * Building shapes of the census parcels (the grey "Fabbricati" of the map), as GeoJSON features with the parcel id.
     *
     * @param  list<array<string, mixed>>  $parcels
     */
    public static function buildings(array $parcels): array
    {
        if ($parcels === []) {
            return ['type' => 'FeatureCollection', 'features' => []];
        }
        $in = implode(',', array_map(fn ($p) => (int) $p['id'], $parcels));
        $rows = DB::select("SELECT l.parcel_id, ST_AsGeoJSON(bv.footprint, 6) AS g FROM building_parcel_links l
            JOIN municipality_catalogs mc ON mc.catalog_release_id = l.catalog_release_id
            JOIN parcels p ON p.id = l.parcel_id AND p.municipality_id = mc.municipality_id
            JOIN building_versions bv ON bv.building_id = l.building_id AND bv.catalog_release_id = l.catalog_release_id AND bv.footprint IS NOT NULL
            WHERE l.parcel_id IN ({$in}) LIMIT 6000");

        return ['type' => 'FeatureCollection', 'features' => array_map(fn ($r) => ['type' => 'Feature', 'properties' => ['parcel' => (int) $r->parcel_id], 'geometry' => json_decode($r->g, true)], $rows)];
    }

    /**
     * Outlines of the Comune inside a viewport (municipal-map-layer.tsx / cartography API): [lat, lng] rings, never invented.
     *
     * @param  array{0: float, 1: float, 2: float, 3: float}  $bounds  south, west, north, east
     * @return array{parcels: list<array<string, mixed>>, limited: bool}
     */
    public static function outlines(string $code, array $bounds, ?int $publicZoneId = null): array
    {
        [$s, $w, $n, $e] = $bounds;
        $zone = $publicZoneId === null ? '' : 'AND EXISTS (SELECT 1 FROM geographic_zones z WHERE z.id = ? AND ST_Covers(z.boundary, COALESCE((SELECT psp.location FROM parcel_search_points psp WHERE psp.parcel_id = p.id AND psp.catalog_release_id = mc.catalog_release_id), ST_PointOnSurface(pv.boundary))))';
        $rows = DB::select("SELECT p.id, p.cadastral_kind, p.section, p.sheet, p.number, ST_AsGeoJSON(pv.boundary, 6) AS g
            FROM parcel_versions pv JOIN municipality_catalogs mc ON mc.catalog_release_id = pv.catalog_release_id
            JOIN municipalities m ON m.id = mc.municipality_id AND m.cadastral_code = ?
            JOIN parcels p ON p.id = pv.parcel_id AND p.municipality_id = m.id
            WHERE pv.boundary && ST_MakeEnvelope(?, ?, ?, ?, 4326) {$zone}
            ORDER BY p.id LIMIT ".(self::MAX_OUTLINES + 1), [strtoupper($code), $w, $s, $e, $n, ...($publicZoneId === null ? [] : [$publicZoneId])]);
        $limited = count($rows) > self::MAX_OUTLINES;
        $parcels = [];
        foreach (array_slice($rows, 0, self::MAX_OUTLINES) as $r) {
            $geometry = json_decode($r->g, true);
            $parcels[] = ['id' => (int) $r->id, 'code' => strtoupper($code), 'kind' => $r->cadastral_kind,
                'section' => (string) $r->section, 'sheet' => (string) $r->sheet, 'parcel' => (string) $r->number,
                'key' => self::outlineKey(strtoupper($code), (string) $r->section, (string) $r->sheet, (string) $r->number, (string) $r->cadastral_kind), 'polygon' => self::rings($geometry)];
        }

        return ['parcels' => $parcels, 'limited' => $limited];
    }

    /** map-selection.ts outlineKey */
    public static function outlineKey(string $code, string $section, string $sheet, string $parcel, string $kind = 'F'): string
    {
        $strip = fn (string $v) => (string) preg_replace('/^0+(?=\d)/', '', $v);

        return json_encode([strtoupper($code), strtoupper($kind), $section === '_' ? '' : strtoupper($section), $strip($sheet), $strip($parcel)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** GeoJSON Polygon/MultiPolygon → polygons of rings of [lat, lng] (DemoMultiPolygon). */
    public static function rings(?array $geometry): array
    {
        $polygons = match ($geometry['type'] ?? null) {
            'Polygon' => [$geometry['coordinates']],
            'MultiPolygon' => $geometry['coordinates'],
            default => [],
        };

        return array_map(fn ($polygon) => array_map(fn ($ring) => array_map(fn ($p) => [$p[1], $p[0]], $ring), $polygon), $polygons);
    }

    /** Outer ring of a single-polygon boundary as an open [lat, lng] list; null when the boundary has several areas. */
    public static function boundaryPoints(?string $geoJson): ?array
    {
        $geometry = $geoJson === null ? null : json_decode($geoJson, true);
        $polygons = self::rings($geometry);
        if (count($polygons) !== 1) {
            return $polygons === [] ? [] : null;
        }
        $ring = $polygons[0][0] ?? [];
        if (count($ring) > 1 && $ring[0] === $ring[count($ring) - 1]) {
            array_pop($ring);
        }

        return $ring;
    }
}
