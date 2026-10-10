<?php

namespace App\Gestionale\Actions\Scouting;

use App\Gestionale\Audit;
use App\Gestionale\CommandRejected;
use App\Gestionale\Commands;
use App\Gestionale\Idempotency;
use App\Gestionale\Scouting\CensusPlan;
use App\Gestionale\Scouting\ScoutingMap;
use App\Gestionale\Scouting\ZoneBoundary;
use App\Models\AgencyMembership;
use App\Models\ScoutingZone;
use App\Models\ScoutingZoneAssignment;
use Illuminate\Support\Facades\DB;

/**
 * census.zone.boundary (census-plan.ts censusPlanAction): the Responsabile draws a zone for a scout, a scout only
 * draws or edits their own. The boundary is stored beside the plan; cadastral shapes and the plan stay untouched.
 */
final class SaveZoneBoundary
{
    public function __construct(private readonly Audit $audit, private readonly Idempotency $idempotency) {}

    /**
     * @param  array{code?: mixed, name?: mixed, operator_id?: mixed, boundary?: mixed, token?: mixed}  $input  boundary: [[lat, lng], …]
     */
    public function handle(AgencyMembership $actor, array $input, ?int $zoneId = null, mixed $revision = null): ScoutingZone
    {
        if (! $actor->isActive() || ! in_array($actor->role, ['admin', 'scout'], true)) {
            throw new CommandRejected('Operazione riservata allo scouting o all’Amministratore.', 403);
        }
        $token = (string) ($input['token'] ?? '');
        if ($zoneId === null && $token !== '') {
            $response = $this->idempotency->run('scouting.zone.boundary', $token, $input, fn () => ['id' => $this->run($actor, $input, null, null)->id]);

            return ScoutingZone::query()->findOrFail($response['id']);
        }

        return DB::transaction(fn () => $this->run($actor, $input, $zoneId, $revision));
    }

    private function run(AgencyMembership $actor, array $input, ?int $zoneId, mixed $revision): ScoutingZone
    {
        $old = $zoneId === null ? null : ScoutingZone::query()->whereKey($zoneId)->lockForUpdate()->first();
        if ($actor->role !== 'admin' && ($old !== null && (int) $old->operator_user_id !== (int) $actor->user_id)) {
            throw new CommandRejected('Puoi modificare soltanto i confini delle tue zone Scout.', 403);
        }
        if ($zoneId !== null && $old === null) {
            throw new CommandRejected('Zona non trovata.');
        }
        if ($old !== null) {
            Commands::assertRevision($old, $revision);
        }

        $code = strtoupper(trim((string) ($input['code'] ?? ($old ? CensusPlan::zoneMunicipalities($old)[0] : 'F205'))));
        $municipality = ScoutingMap::hasCatalog($code) ? $code : null;
        if ($municipality === null || ($old !== null && (count(CensusPlan::zoneMunicipalities($old)) !== 1 || CensusPlan::zoneMunicipalities($old)[0] !== $municipality))) {
            throw new CommandRejected('I confini devono restare associati allo stesso Comune della zona.');
        }
        $boundary = ZoneBoundary::validate($input['boundary'] ?? null);
        $name = trim((string) (($input['name'] ?? '') ?: ($old?->name ?? '')));
        $operatorId = $old?->operator_user_id ?: ($actor->role === 'scout' ? (int) $actor->user_id : (int) ($input['operator_id'] ?? 0));
        // The owner may inspect the scoped scouting profile without changing the stored role: a new zone then belongs to self.
        $validOperator = AgencyMembership::query()->where('agency_id', $actor->agency_id)->where('user_id', $operatorId)->where('role', 'scout')->active()->exists()
            && ($actor->role !== 'scout' || (int) $operatorId === (int) $actor->user_id);
        if ($name === '' || mb_strlen($name) > 120) {
            throw new CommandRejected('Scrivi un nome per la zona, fino a 120 caratteri.');
        }
        if (! $validOperator) {
            throw new CommandRejected($actor->role === 'scout'
                ? 'L’agente acquisizioni viene assegnato automaticamente alla propria zona. Riapri il disegno e riprova.'
                : 'Scegli un agente acquisizioni attivo per questa zona.');
        }
        if ($old !== null && $old->name === $name && $this->sameBoundary($old, $boundary)) {
            return $old;
        }

        $before = $old?->only(['name']);
        $zone = $old ?? new ScoutingZone;
        $zone->forceFill($old ? ['name' => $name] : [
            'operator_user_id' => $operatorId, 'name' => $name, 'status' => 'Pianificata', 'starts_on' => today(), 'municipalities' => [$municipality], 'notes' => null,
        ]);
        $zone->updated_at = now();
        $zone->save();
        DB::update('UPDATE scouting_zones SET boundary = ST_Multi(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326)) WHERE agency_id = ? AND id = ?', [json_encode(ZoneBoundary::geoJson($boundary)), $actor->agency_id, $zone->id]);
        if (! ScoutingZoneAssignment::query()->where('scouting_zone_id', $zone->id)->where('user_id', $operatorId)->exists()) {
            (new ScoutingZoneAssignment)->forceFill(['agency_id' => $actor->agency_id, 'scouting_zone_id' => $zone->id, 'user_id' => $operatorId])->save();
        }
        $this->audit->record('census.zone.boundary', $zone, ['code' => $municipality, 'name' => $name, 'vertices' => count($boundary), 'before' => $before]);

        return $zone->refresh();
    }

    private function sameBoundary(ScoutingZone $zone, array $boundary): bool
    {
        $stored = ScoutingMap::boundaryPoints(DB::selectOne('SELECT ST_AsGeoJSON(boundary, 15) AS g FROM scouting_zones WHERE agency_id = ? AND id = ?', [$zone->agency_id, $zone->id])?->g);
        if ($stored === null || count($stored) !== count($boundary)) {
            return false;
        }
        foreach ($stored as $i => $point) {
            if (abs($point[0] - $boundary[$i][0]) > 1e-9 || abs($point[1] - $boundary[$i][1]) > 1e-9) {
                return false;
            }
        }

        return true;
    }
}
