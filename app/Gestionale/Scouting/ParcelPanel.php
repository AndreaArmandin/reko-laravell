<?php

namespace App\Gestionale\Scouting;

use App\Models\AgencyMembership;
use App\Trova\Presentation;
use Illuminate\Support\Facades\DB;

/**
 * Data of the selection panel of the map (parcel-catalog.tsx, parcel-measurements.tsx, scout-zones.tsx aside):
 * the parcel by cadastral identity, its units in the catalog, the census data visible to the operator and the measures.
 */
final class ParcelPanel
{
    public const UNITS_PER_PAGE = 10;

    /** Parcel (id, release, municipality) from its cadastral identity; section "_" means none. */
    public static function find(string $code, string $section, string $sheet, string $parcel, string $kind = 'F'): ?object
    {
        $strip = fn (string $v) => (string) preg_replace('/^0+(?=\d)/', '', $v);
        $norm = fn (string $column) => "CASE WHEN {$column} ~ '^[0-9]+\$' THEN regexp_replace({$column}, '^0+(?=[0-9])', '') ELSE upper(btrim({$column})) END";
        if (! in_array(strtoupper($kind), ['F', 'T'], true)) {
            return null;
        }

        return DB::selectOne("SELECT p.id, p.section, p.sheet, p.number, m.name AS municipality, m.cadastral_code AS code, mc.catalog_release_id AS release_id, tp.abbreviation AS province
            FROM parcels p JOIN municipalities m ON m.id = p.municipality_id
            LEFT JOIN municipality_catalogs mc ON mc.municipality_id = m.id LEFT JOIN territorial_provinces tp ON tp.id = m.territorial_province_id
            WHERE m.cadastral_code = ? AND p.cadastral_kind = ? AND upper(p.section) = ? AND {$norm('p.sheet')} = ? AND {$norm('p.number')} = ?
            ORDER BY p.id LIMIT 1", [strtoupper($code), strtoupper($kind), $section === '_' ? '' : strtoupper($section), $strip($sheet), $strip($parcel)]);
    }

    /**
     * Units of the parcel in the active catalog edition (the "immobili presenti in REKO").
     *
     * @return array{total: int, pages: int, rows: list<array{key: string, sub: string, category: string, consistency: string, address: string}>}
     */
    public static function units(int $parcelId, int $page = 1): array
    {
        $base = fn () => DB::table('cadastral_units as cu')->join('parcels as p', 'p.id', '=', 'cu.parcel_id')
            ->join('municipality_catalogs as mc', 'mc.municipality_id', '=', 'p.municipality_id')
            ->join('cadastral_unit_versions as v', fn ($j) => $j->on('v.cadastral_unit_id', '=', 'cu.id')->on('v.catalog_release_id', '=', 'mc.catalog_release_id'))
            ->where('cu.parcel_id', $parcelId)->where('v.status', 'eligible');
        $total = $base()->count();
        $pages = max(1, (int) ceil($total / self::UNITS_PER_PAGE));
        $page = min(max(1, $page), $pages);
        $rows = $base()->orderBy('cu.id')->forPage($page, self::UNITS_PER_PAGE)->get(['cu.id', 'cu.subalterno', 'v.category', 'v.consistency', 'v.consistency_unit', 'v.address_raw']);

        return ['total' => $total, 'pages' => $pages, 'page' => $page, 'rows' => $rows->map(fn ($r) => [
            'key' => (string) $r->id, 'sub' => (string) $r->subalterno, 'category' => (string) $r->category,
            'consistency' => $r->consistency === null ? 'Non indicata' : Presentation::measure($r->consistency, $r->consistency_unit),
            'address' => Presentation::address((string) $r->address_raw),
        ])->all()];
    }

    /**
     * "Dati del censimento": active units, owners (current ownerships of the agency) and last outcome of a census parcel.
     *
     * @return array{units: int, owners: int, outcome: string}
     */
    public static function census(AgencyMembership $actor, int $parcelId): array
    {
        $units = (int) self::units($parcelId, 1)['total'];
        $owners = DB::table('ownerships as o')->join('contacts as c', 'c.id', '=', 'o.contact_id')
            ->where('o.agency_id', $actor->agency_id)->whereNull('o.valid_to')->whereNull('c.removed_at')
            ->whereRaw('(o.parcel_id = ? OR o.cadastral_unit_id IN (SELECT id FROM cadastral_units WHERE parcel_id = ?))', [$parcelId, $parcelId])
            ->distinct()->count('o.contact_id');

        return ['units' => $units, 'owners' => $owners, 'outcome' => ScoutingMap::lastOutcomes($actor, [$parcelId])[$parcelId] ?? 'Nessun esito'];
    }

    /**
     * Measures of the whole parcel (m²): lot area from the catalog, covered area from building shapes inside it. Null when not verified.
     *
     * @return array{lot: float|null, covered: float|null, uncovered: float|null, notice: string}
     */
    public static function measurements(int $parcelId): array
    {
        $row = DB::selectOne('SELECT pv.area_sqm AS lot,
                (SELECT ST_Area(ST_Intersection(pv.boundary, ST_Union(bv.footprint))::geography) FROM building_parcel_links l JOIN building_versions bv ON bv.building_id = l.building_id AND bv.catalog_release_id = l.catalog_release_id
                  WHERE l.parcel_id = p.id AND l.catalog_release_id = mc.catalog_release_id AND bv.footprint IS NOT NULL HAVING count(*) > 0) AS covered
            FROM parcels p JOIN municipality_catalogs mc ON mc.municipality_id = p.municipality_id
            JOIN parcel_versions pv ON pv.parcel_id = p.id AND pv.catalog_release_id = mc.catalog_release_id WHERE p.id = ?', [$parcelId]);
        $lot = $row?->lot === null ? null : (float) $row->lot;
        $covered = $lot === null || $row?->covered === null ? null : min((float) $row->covered, $lot);

        return ['lot' => $lot, 'covered' => $covered, 'uncovered' => $lot !== null && $covered !== null ? max(0.0, $lot - $covered) : null,
            'notice' => $lot === null
                ? 'Misure non verificate per questa particella: nessuna superficie viene stimata.'
                : 'Misure cartografiche dell’intera particella, non dell’unità né di un giardino esclusivo.'.($covered !== null ? ' Coperto e scoperto sono misurati entro il perimetro della particella; i ritagli non rappresentano l’intero fabbricato.' : '')];
    }
}
