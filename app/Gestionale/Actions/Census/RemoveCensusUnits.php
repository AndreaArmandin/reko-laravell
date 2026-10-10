<?php

namespace App\Gestionale\Actions\Census;

use App\Gestionale\Audit;
use App\Gestionale\Census\CensusScope;
use App\Gestionale\CommandRejected;
use App\Models\AgencyMembership;
use App\Models\AgencyUnitObservation;
use Illuminate\Support\Facades\DB;

/**
 * Logical, audited removal of selected census units. Cadastral identity, ownerships,
 * activities and their histories remain intact; linked portfolio records are flagged for review.
 */
final class RemoveCensusUnits extends CensusCommand
{
    public function __construct(private readonly Audit $audit) {}

    /** @param list<int|string> $unitIds */
    public function handle(AgencyMembership $actor, array $unitIds, string $reason): int
    {
        self::gate($actor);
        self::admin($actor);

        $ids = collect($unitIds)->map(function ($id) {
            if ((! is_int($id) && (! is_string($id) || ! ctype_digit($id))) || (int) $id < 1) {
                throw new CommandRejected('Seleziona unità catastali valide.');
            }

            return (int) $id;
        })->unique()->values()->all();
        if ($ids === [] || count($ids) > 100) {
            throw new CommandRejected('Seleziona da 1 a 100 unità per operazione.');
        }
        $reason = self::reason(['reason' => $reason]);

        return DB::transaction(function () use ($actor, $ids, $reason): int {
            $observations = AgencyUnitObservation::query()->where('agency_id', $actor->agency_id)
                ->whereIn('cadastral_unit_id', $ids)->orderBy('cadastral_unit_id')->lockForUpdate()->get()->keyBy('cadastral_unit_id');
            if ($observations->count() !== count($ids)) {
                throw new CommandRejected('Una o più unità selezionate non sono più accessibili. Aggiorna l’elenco.');
            }

            foreach ($ids as $id) {
                if (! CensusScope::unitVisible($actor, $id)) {
                    throw new CommandRejected('Unità non accessibile.', 403);
                }
                $unit = $observations[$id];
                if ($unit->state !== 'Attivo' || ! empty($unit->removed)) {
                    throw new CommandRejected('Una o più unità non sono più attive. Aggiorna l’elenco.');
                }

                $before = $unit->only(['state', 'removed']);
                $unit->forceFill(['state' => 'Eliminato', 'removed' => [
                    'at' => now()->toIso8601String(), 'actorId' => $actor->user_id,
                    'reason' => $reason, 'kind' => 'Eliminazione logica unità',
                ]])->save();

                $propertyIds = DB::table('property_units')->where('agency_id', $actor->agency_id)
                    ->where('cadastral_unit_id', $id)->pluck('property_id')->all();
                if ($propertyIds !== []) {
                    DB::table('properties')->where('agency_id', $actor->agency_id)->whereIn('id', $propertyIds)
                        ->update(['cadastral_warning' => 'Verifica catastale necessaria', 'updated_at' => now()]);
                }

                $this->audit->record('census.unit.delete', $unit, [
                    'before' => $before, 'after' => $unit->only(['state', 'removed']), 'propertyIds' => $propertyIds,
                ], $reason);
            }

            return count($ids);
        });
    }
}
