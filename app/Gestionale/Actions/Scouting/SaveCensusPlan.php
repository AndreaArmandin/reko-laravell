<?php

namespace App\Gestionale\Actions\Scouting;

use App\Gestionale\Audit;
use App\Gestionale\CommandRejected;
use App\Gestionale\Commands;
use App\Gestionale\Idempotency;
use App\Gestionale\Scouting\CensusPlan;
use App\Models\AgencyMembership;
use App\Models\ScoutingZone;
use App\Models\ScoutingZoneAssignment;
use Illuminate\Support\Facades\DB;

/**
 * census.zone.save (census-plan.ts): name, operator, status and plan of a zone ("Nuova Scouting Zone" / "Piano di
 * censimento"). Only the Responsabile. The parcels of the plan found in the catalog are assigned to the operator.
 */
final class SaveCensusPlan
{
    public function __construct(private readonly Audit $audit, private readonly Idempotency $idempotency) {}

    /**
     * @param  array{name?: mixed, description?: mixed, operator_id?: mixed, status?: mixed, start?: mixed, plan?: mixed, token?: mixed}  $input  plan: rows as parsed by CensusPlan::parse
     */
    public function handle(AgencyMembership $actor, array $input, ?int $zoneId = null, mixed $revision = null): ScoutingZone
    {
        if ($actor->isActive() && $actor->role === 'crm') {
            throw new CommandRejected('Operazione riservata allo scouting o all’Amministratore.', 403);
        }
        if (! $actor->isActive() || $actor->role !== 'admin') {
            throw new CommandRejected('Configurazione riservata all’Amministratore.', 403);
        }
        $token = (string) ($input['token'] ?? '');
        if ($zoneId === null && $token !== '') {
            $response = $this->idempotency->run('scouting.zone.plan', $token, $input, fn () => ['id' => $this->run($actor, $input, null, null)->id]);

            return ScoutingZone::query()->findOrFail($response['id']);
        }

        return DB::transaction(fn () => $this->run($actor, $input, $zoneId, $revision));
    }

    private function run(AgencyMembership $actor, array $input, ?int $zoneId, mixed $revision): ScoutingZone
    {
        $previous = $zoneId === null ? null : ScoutingZone::query()->whereKey($zoneId)->lockForUpdate()->first();
        $name = Commands::text($input['name'] ?? '', 255);
        $plan = $input['plan'] ?? null;
        $operatorId = (int) ($input['operator_id'] ?? 0);
        $operator = AgencyMembership::query()->where('agency_id', $actor->agency_id)->where('user_id', $operatorId)->where('role', 'scout')->active()->exists();
        if ($name === '' || ! is_array($plan) || $plan === [] || count($plan) > CensusPlan::MAX_ITEMS || ! $operator) {
            throw new CommandRejected('Indica nome, operatore e almeno una particella del piano.');
        }

        $validated = array_map([CensusPlan::class, 'validateItem'], array_values($plan));
        if (count(array_unique(array_map([CensusPlan::class, 'key'], $validated))) !== count($validated)) {
            throw new CommandRejected('Il piano contiene particelle duplicate.');
        }
        $codes = array_values(array_unique(array_column($validated, 'code')));
        if ($previous !== null && $this->hasBoundary($previous) && (count($codes) !== 1 || count(CensusPlan::zoneMunicipalities($previous)) !== 1 || $codes[0] !== CensusPlan::zoneMunicipalities($previous)[0])) {
            throw new CommandRejected('Non puoi spostare in un altro Comune una zona con confini già salvati. Crea una zona separata.');
        }
        $status = in_array($input['status'] ?? null, CensusPlan::STATUSES, true) ? $input['status'] : 'Pianificata';
        $zone = $previous ?? new ScoutingZone;
        if ($status === 'Chiusa' && CensusPlan::progress($zone, $validated)['percent'] < 100) {
            throw new CommandRejected('Il piano non è completo: verifica le particelle ancora da censire, le anomalie e i recapiti mancanti prima di chiudere la zona.');
        }
        if ($zoneId !== null && $previous === null) {
            throw new CommandRejected('Zona non trovata.');
        }
        if ($previous !== null) {
            Commands::assertRevision($previous, $revision);
        }

        $start = trim((string) ($input['start'] ?? ''));
        $zone->forceFill([
            'name' => $name, 'notes' => Commands::text($input['description'] ?? '', 4000) ?: null, 'operator_user_id' => $operatorId, 'status' => $status,
            'starts_on' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) && strtotime($start) !== false ? $start : null,
            'plan' => $validated, 'municipalities' => $codes,
        ]);
        $zone->updated_at = now();
        $zone->save();

        // The zone belongs to its operator (a single one, as in the gestionale)
        ScoutingZoneAssignment::query()->where('scouting_zone_id', $zone->id)->where('user_id', '<>', $operatorId)->delete();
        if (! ScoutingZoneAssignment::query()->where('scouting_zone_id', $zone->id)->where('user_id', $operatorId)->exists()) {
            (new ScoutingZoneAssignment)->forceFill(['agency_id' => $actor->agency_id, 'scouting_zone_id' => $zone->id, 'user_id' => $operatorId])->save();
        }
        $assigned = $this->assignParcels($actor, $zone, $validated);
        $this->audit->record('census.zone.save', $zone, ['name' => $name, 'status' => $status, 'operator' => $operatorId, 'parcels' => count($validated), 'assigned' => $assigned]);

        return $zone->refresh();
    }

    /** parcelAgents[p.id] = zone.operatorId for every parcel of the plan that is in the catalog. */
    private function assignParcels(AgencyMembership $actor, ScoutingZone $zone, array $plan): int
    {
        $ids = array_values(array_map(fn ($p) => (int) $p->id, CensusPlan::parcels($plan)));
        if ($ids === []) {
            return 0;
        }
        $existing = DB::table('scouting_assignments')->where('agency_id', $actor->agency_id)->whereNull('cadastral_unit_id')->whereIn('parcel_id', $ids)->pluck('parcel_id')->all();
        DB::table('scouting_assignments')->where('agency_id', $actor->agency_id)->whereNull('cadastral_unit_id')->whereIn('parcel_id', $existing)
            ->update(['user_id' => $zone->operator_user_id, 'scouting_zone_id' => $zone->id, 'updated_at' => now()]);
        $rows = array_map(fn ($id) => ['agency_id' => $actor->agency_id, 'user_id' => $zone->operator_user_id, 'parcel_id' => $id, 'scouting_zone_id' => $zone->id, 'status' => 'Da contattare', 'created_at' => now(), 'updated_at' => now()], array_values(array_diff($ids, $existing)));
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('scouting_assignments')->insert($chunk);
        }

        return count($ids);
    }

    private function hasBoundary(ScoutingZone $zone): bool
    {
        return DB::table('scouting_zones')->where('agency_id', $zone->agency_id)->where('id', $zone->id)->whereNotNull('boundary')->exists();
    }
}
