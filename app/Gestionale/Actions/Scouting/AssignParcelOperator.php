<?php

namespace App\Gestionale\Actions\Scouting;

use App\Gestionale\Audit;
use App\Gestionale\CommandRejected;
use App\Models\AgencyMembership;
use App\Models\Parcel;
use Illuminate\Support\Facades\DB;

/** parcel.assign (engine.ts): the Responsabile assigns an active scout to a parcel (parcelAgents). */
final class AssignParcelOperator
{
    public function __construct(private readonly Audit $audit) {}

    public function handle(AgencyMembership $actor, int $parcelId, int $userId): void
    {
        if (! $actor->isActive() || $actor->role === 'crm') {
            throw new CommandRejected('Operazione riservata allo scouting o all’Amministratore.', 403);
        }
        if ($actor->role !== 'admin') {
            throw new CommandRejected('Questa operazione è riservata all’amministratore.', 403);
        }
        $parcel = Parcel::query()->find($parcelId);
        $scout = AgencyMembership::query()->where('agency_id', $actor->agency_id)->where('user_id', $userId)->where('role', 'scout')->active()->exists();
        if ($parcel === null || ! $scout) {
            throw new CommandRejected('Scegli una particella e un Operatore 1 attivo.');
        }

        DB::transaction(function () use ($actor, $parcel, $userId) {
            $row = DB::table('scouting_assignments')->where('agency_id', $actor->agency_id)->where('parcel_id', $parcel->id)->whereNull('cadastral_unit_id')->lockForUpdate()->first();
            if ($row === null) {
                DB::table('scouting_assignments')->insert(['agency_id' => $actor->agency_id, 'user_id' => $userId, 'parcel_id' => $parcel->id, 'status' => 'Da contattare', 'created_at' => now(), 'updated_at' => now()]);
            } elseif ((int) $row->user_id !== $userId) {
                DB::table('scouting_assignments')->where('id', $row->id)->update(['user_id' => $userId, 'updated_at' => now()]);
            }
            $this->audit->record('parcel.assign', $parcel, ['parcel_id' => $parcel->id, 'before' => $row?->user_id, 'after' => $userId]);
        });
    }
}
