<?php

namespace App\Gestionale\Census;

use App\Models\AgencyMembership;
use App\Models\ScoutingAssignment;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Che cosa vede ciascun ruolo nell'archivio catastale (permissions.ts visibleEntities per le particelle):
 * - Responsabile (admin): tutto il censimento dell'agenzia;
 * - Agente acquisizioni (scout): solo le particelle assegnate (scouting_assignments, per particella o per unità);
 * - Segreteria (crm): la sezione non è accessibile (queryCensus, 403).
 * L'operatore di una particella (parcelAgents) è la riga di assegnazione per particella.
 */
final class CensusScope
{
    public const NOT_ALLOWED = 'Sezione Proprietari non accessibile.';

    public static function canUse(?AgencyMembership $m): bool
    {
        return $m !== null && $m->isActive() && in_array($m->role, ['admin', 'scout'], true);
    }

    /** Schede di censimento che il membro può vedere, con particella, Comune e provincia. */
    public static function units(AgencyMembership $m): Builder
    {
        $query = DB::table('agency_unit_observations as o')
            ->join('cadastral_units as cu', 'cu.id', '=', 'o.cadastral_unit_id')
            ->join('parcels as p', 'p.id', '=', 'cu.parcel_id')
            ->join('municipalities as mu', 'mu.id', '=', 'p.municipality_id')
            ->leftJoin('territorial_provinces as tp', 'tp.id', '=', 'mu.territorial_province_id')
            ->where('o.agency_id', $m->agency_id);

        if (! $m->isActive() || ! in_array($m->role, ['admin', 'scout'], true)) {
            return $query->whereRaw('false');
        }
        if ($m->role === 'scout') {
            $query->where(fn (Builder $v) => $v
                ->whereExists(fn (Builder $a) => $a->selectRaw('1')->from('scouting_assignments as sa')
                    ->whereColumn('sa.agency_id', 'o.agency_id')->where('sa.user_id', $m->user_id)
                    ->where(fn (Builder $x) => $x->whereColumn('sa.cadastral_unit_id', 'o.cadastral_unit_id')->orWhereColumn('sa.parcel_id', 'cu.parcel_id'))));
        }

        return $query;
    }

    /** @return list<int> particelle assegnate allo scout */
    public static function assignedParcelIds(AgencyMembership $m): array
    {
        return ScoutingAssignment::query()->where('user_id', $m->user_id)->get(['parcel_id', 'cadastral_unit_id'])
            ->flatMap(fn ($a) => [$a->parcel_id, $a->cadastral_unit_id ? DB::table('cadastral_units')->where('id', $a->cadastral_unit_id)->value('parcel_id') : null])
            ->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    public static function unitVisible(AgencyMembership $m, int $unitId): bool
    {
        return self::units($m)->where('o.cadastral_unit_id', $unitId)->exists();
    }

    public static function parcelVisible(AgencyMembership $m, int $parcelId): bool
    {
        return self::units($m)->where('cu.parcel_id', $parcelId)->exists();
    }

    /** Operatore della particella (parcelAgents): l'assegnazione per particella. */
    public static function parcelOperator(int $agencyId, int $parcelId): ?int
    {
        $id = DB::table('scouting_assignments')->where('agency_id', $agencyId)->where('parcel_id', $parcelId)
            ->whereNull('cadastral_unit_id')->orderBy('id')->value('user_id');

        return $id === null ? null : (int) $id;
    }

    /** parcel.assign: una particella ha un solo operatore. */
    public static function assignParcel(int $agencyId, int $parcelId, int $userId, ?int $zoneId = null): void
    {
        $row = ScoutingAssignment::query()->where('parcel_id', $parcelId)->whereNull('cadastral_unit_id')->orderBy('id')->first();
        if ($row === null) {
            ScoutingAssignment::query()->create(['user_id' => $userId, 'parcel_id' => $parcelId, 'scouting_zone_id' => $zoneId]);

            return;
        }
        if ((int) $row->user_id !== $userId || ($zoneId !== null && (int) $row->scouting_zone_id !== $zoneId)) {
            $row->forceFill(['user_id' => $userId, 'scouting_zone_id' => $zoneId ?? $row->scouting_zone_id])->save();
        }
    }

    /** Proprietario raggiungibile: admin tutti; scout chi ha importato l'anagrafica o ha un'intestazione su una particella assegnata. */
    public static function ownerVisible(AgencyMembership $m, int $contactId): bool
    {
        if (! self::canUse($m)) {
            return false;
        }
        $exists = DB::table('contacts')->where('agency_id', $m->agency_id)->where('id', $contactId)->exists();
        if (! $exists || $m->role === 'admin') {
            return $exists;
        }
        if (DB::table('contacts')->where('id', $contactId)->where('imported_by_user_id', $m->user_id)->exists()) {
            return true;
        }

        return self::units($m)->whereExists(fn (Builder $w) => $w->selectRaw('1')->from('ownerships as w')
            ->whereColumn('w.cadastral_unit_id', 'o.cadastral_unit_id')->where('w.contact_id', $contactId)->whereNull('w.valid_to'))->exists();
    }
}
