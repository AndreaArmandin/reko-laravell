<?php

namespace App\Trova;

use Illuminate\Support\Facades\DB;

/**
 * Builds trova_unit_facts for one catalogue release: floors and v4 housing classification
 * of every eligible unit, parcel by parcel, like Trova's precomputed housing index.
 */
final class UnitFacts
{
    /** Rebuilds the facts of a release. Returns the number of units written. */
    public function build(int $releaseId): int
    {
        $code = (string) DB::table('catalog_releases')
            ->join('municipalities', 'municipalities.id', '=', 'catalog_releases.municipality_id')
            ->where('catalog_releases.id', $releaseId)->value('municipalities.cadastral_code');

        $units = DB::table('cadastral_unit_versions as v')
            ->join('cadastral_units as u', 'u.id', '=', 'v.cadastral_unit_id')
            ->join('parcels as p', 'p.id', '=', 'u.parcel_id')
            ->where('v.catalog_release_id', $releaseId)
            ->where('v.status', 'eligible')
            ->orderBy('u.parcel_id')->orderBy('u.id')
            ->get(['u.id', 'u.parcel_id', 'u.subalterno', 'v.category', 'v.address_raw', 'p.section', 'p.sheet', 'p.number']);

        $rows = [];
        foreach ($units->groupBy('parcel_id') as $parcelId => $parcel) {
            $list = array_values($parcel->values()->map(fn ($u) => [
                'sub' => (string) $u->subalterno,
                'categoria' => (string) $u->category,
                'address' => (string) $u->address_raw,
            ])->all());
            $first = $parcel->first();
            $housing = HousingV4::classify($list, [
                'code' => $code, 'section' => (string) $first->section, 'sheet' => (string) $first->sheet, 'parcel' => (string) $first->number,
            ])['units'];
            $apartmentParcel = HousingV4::apartmentParcel($list);

            $levels = array_map(fn ($u) => HousingV4::levels(HousingV4::address($u['address'])['piano']), $list);
            // Top floor of the parcel's homes; unknown when any home floor is unreadable (v4_top_floors)
            $homeTops = [];
            $allKnown = true;
            foreach ($list as $i => $u) {
                if (str_starts_with(mb_strtoupper($u['categoria']), 'A/') && mb_strtoupper($u['categoria']) !== 'A/10') {
                    $levels[$i] === null ? $allKnown = false : $homeTops[] = max($levels[$i]);
                }
            }
            $parcelTop = $allKnown && $homeTops !== [] ? max($homeTops) : null;

            foreach ($parcel->values() as $i => $u) {
                $h = $housing[$i] ?? null;
                $rows[] = [
                    'catalog_release_id' => $releaseId,
                    'cadastral_unit_id' => $u->id,
                    'parcel_id' => $parcelId,
                    'floor_levels' => self::array(Floors::residential((string) $u->address_raw)),
                    'v4_levels' => self::array($levels[$i]),
                    'v4_highest' => $levels[$i] !== null ? max($levels[$i]) : null,
                    'parcel_top_floor' => $parcelTop,
                    'housing_outcome' => $h['esito'] ?? null,
                    'housing_confidence' => $h['conf'] ?? null,
                    'housing_levels' => $h['livelli_txt'] ?? null,
                    'housing_notes' => $h !== null ? json_encode($h['note'], JSON_UNESCAPED_UNICODE) : null,
                    'vertical_independent' => $h['vertical'] ?? false,
                    'apartment_parcel' => $apartmentParcel,
                ];
            }
        }

        DB::transaction(function () use ($releaseId, $rows) {
            DB::table('trova_unit_facts')->where('catalog_release_id', $releaseId)->delete();
            foreach (array_chunk($rows, 1000) as $chunk) {
                DB::table('trova_unit_facts')->insert($chunk);
            }
        });

        return count($rows);
    }

    /**
     * PostgreSQL array literal: {0,1,-1}
     *
     * @param  list<int>|null  $levels
     */
    private static function array(?array $levels): ?string
    {
        return $levels === null ? null : '{'.implode(',', $levels).'}';
    }
}
