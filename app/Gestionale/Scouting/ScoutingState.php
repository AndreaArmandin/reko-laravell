<?php

namespace App\Gestionale\Scouting;

use App\Gestionale\Activities\ActivityCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Port of lib/crm/scouting.ts scoutingState (+ goals.ts validContactOutcome):
 * 0 Non censita, 1 Censimento iniziato, 2 Attività in corso, 3 Completata.
 */
final class ScoutingState
{
    public const LABELS = [0 => 'Non censita', 1 => 'Censimento iniziato', 2 => 'Attività in corso', 3 => 'Completata'];

    /** goals.ts validContactOutcome */
    public static function validContactOutcome(string $outcome, ?string $operation): bool
    {
        return in_array($outcome, ActivityCatalog::LEGACY_OUTCOMES, true)
            || ($operation !== null && $operation !== '' && in_array($outcome, ActivityCatalog::answeredContactOutcomes(), true));
    }

    /**
     * @param  array{units: list<int>, owners: list<array{id: int, units: list<int>}>, activities: list<array<string, mixed>>}  $parcel
     *                                                                                                                              activities: already permission-filtered, as the caller supplies them
     *                                                                                                                              (owner, units, status, done, outcome, confirmed_at, answered, operation)
     */
    public static function of(array $parcel): int
    {
        $relevant = array_values(array_filter($parcel['activities'], fn ($a) => ($a['status'] ?? null) !== 'Annullata'));
        $owners = $parcel['owners'];
        if ($owners !== [] && collect($owners)->every(function (array $owner) use ($relevant) {
            if ($owner['units'] === []) {
                return false;
            }

            return collect($owner['units'])->every(function (int $sub) use ($owner, $relevant) {
                $latest = collect($relevant)
                    ->filter(fn ($a) => ($a['owner'] ?? null) === $owner['id'] && in_array($sub, $a['units'] ?? [], true) && ($a['done'] ?? false) && ! empty($a['confirmed_at']))
                    ->sortByDesc(fn ($a) => (string) $a['confirmed_at'])->first();

                return $latest !== null && ($latest['answered'] ?? false) && self::validContactOutcome((string) ($latest['outcome'] ?? ''), $latest['operation'] ?? null);
            });
        })) {
            return 3;
        }
        if ($relevant !== []) {
            return 2;
        }

        return $parcel['units'] !== [] || $owners !== [] ? 1 : 0;
    }

    /**
     * State of each parcel of an agency, from the stored census (units of the active catalog edition,
     * current ownerships, activities linked to the parcel).
     *
     * @param  list<int>  $parcelIds
     * @param  int|null  $visibleTo  user whose activities are visible (null: all, as the Responsabile)
     * @return array<int, int>
     */
    public static function forParcels(int $agencyId, array $parcelIds, ?int $visibleTo = null): array
    {
        if ($parcelIds === []) {
            return [];
        }
        $in = implode(',', array_map('intval', $parcelIds));
        $units = DB::table('cadastral_units')->whereIn('parcel_id', $parcelIds)->orderBy('id')->get(['id', 'parcel_id'])->groupBy('parcel_id');
        $unitParcel = DB::table('cadastral_units')->whereIn('parcel_id', $parcelIds)->pluck('parcel_id', 'id');

        // Ownerships on a unit, or on the whole parcel (then on every unit of it)
        $owners = [];
        $rows = DB::table('ownerships as o')->join('contacts as c', 'c.id', '=', 'o.contact_id')
            ->where('o.agency_id', $agencyId)->whereNull('o.valid_to')->whereNull('c.removed_at')
            ->whereRaw("(o.parcel_id IN ({$in}) OR o.cadastral_unit_id IN (SELECT id FROM cadastral_units WHERE parcel_id IN ({$in})))")
            ->get(['o.contact_id', 'o.parcel_id', 'o.cadastral_unit_id']);
        foreach ($rows as $row) {
            $parcelId = $row->cadastral_unit_id !== null ? $unitParcel[$row->cadastral_unit_id] ?? null : $row->parcel_id;
            if ($parcelId === null) {
                continue;
            }
            $ids = $row->cadastral_unit_id !== null ? [(int) $row->cadastral_unit_id] : $units->get($parcelId, collect())->pluck('id')->map(fn ($id) => (int) $id)->all();
            $owners[$parcelId][$row->contact_id] = array_values(array_unique([...($owners[$parcelId][$row->contact_id] ?? []), ...$ids]));
        }

        $extended = Schema::hasColumn('activities', 'owner_contact_id');
        $activities = DB::table('activities as a')->where('a.agency_id', $agencyId)->whereIn('a.parcel_id', $parcelIds)
            ->when($visibleTo !== null, fn ($q) => $q->where(fn ($w) => $w->where('a.assigned_to_user_id', $visibleTo)->orWhere('a.created_by_user_id', $visibleTo)->orWhere('a.user_id', $visibleTo)))
            ->get(['a.id', 'a.parcel_id', 'a.status', 'a.outcome', 'a.outcome_confirmed_at', ...($extended ? ['a.owner_contact_id', 'a.answered', 'a.contact_operation'] : [])]);
        $activityUnits = $activities->isEmpty() ? collect() : DB::table('activity_units')->whereIn('activity_id', $activities->pluck('id'))->get(['activity_id', 'cadastral_unit_id'])->groupBy('activity_id');

        $byParcel = [];
        foreach ($activities as $a) {
            $byParcel[$a->parcel_id][] = [
                'owner' => $extended && $a->owner_contact_id !== null ? (int) $a->owner_contact_id : null,
                'units' => $activityUnits->get($a->id, collect())->pluck('cadastral_unit_id')->map(fn ($id) => (int) $id)->all(),
                'status' => $a->status,
                'done' => $a->status === 'Completata',
                'outcome' => $a->outcome,
                'confirmed_at' => $a->outcome_confirmed_at,
                'answered' => $extended && (bool) $a->answered,
                'operation' => $extended ? $a->contact_operation : null,
            ];
        }

        $result = [];
        foreach ($parcelIds as $id) {
            $result[$id] = self::of([
                'units' => $units->get($id, collect())->pluck('id')->map(fn ($u) => (int) $u)->all(),
                'owners' => collect($owners[$id] ?? [])->map(fn ($u, $contact) => ['id' => (int) $contact, 'units' => $u])->values()->all(),
                'activities' => $byParcel[$id] ?? [],
            ]);
        }

        return $result;
    }
}
