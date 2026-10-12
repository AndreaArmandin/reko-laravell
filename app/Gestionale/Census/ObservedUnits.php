<?php

namespace App\Gestionale\Census;

use Illuminate\Support\Facades\DB;

/**
 * State of the agency's census units (census-model.ts activeUnit): a unit is active unless its observation
 * is marked suppressed (state other than "Attivo") or removed.
 */
final class ObservedUnits
{
    /** SQL condition on the alias "o" (agency_unit_observations): the unit is suppressed or removed. */
    public const INACTIVE = "(COALESCE(o.state, 'Attivo') <> 'Attivo' OR NOT (o.removed IS NULL OR o.removed::text IN ('null', '[]', '{}')))";

    /** @param  list<int>  $unitIds */
    public static function anyInactive(int $agencyId, array $unitIds): bool
    {
        if ($unitIds === []) {
            return false;
        }

        return DB::table('agency_unit_observations as o')->where('o.agency_id', $agencyId)
            ->whereIn('o.cadastral_unit_id', $unitIds)->whereRaw(self::INACTIVE)->exists();
    }

    /** Whether an activity is linked to at least one suppressed or removed unit of the agency. */
    public static function activityHasInactive(int $agencyId, int $activityId): bool
    {
        return DB::table('activity_units as au')
            ->join('agency_unit_observations as o', fn ($join) => $join->on('o.cadastral_unit_id', '=', 'au.cadastral_unit_id')->where('o.agency_id', $agencyId))
            ->where('au.activity_id', $activityId)->whereRaw(self::INACTIVE)->exists();
    }
}
