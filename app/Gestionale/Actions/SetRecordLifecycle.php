<?php

namespace App\Gestionale\Actions;

use App\Gestionale\Audit;
use App\Gestionale\CommandRejected;
use App\Models\AgencyMembership;
use App\Models\ClientProfile;
use App\Models\PropertyRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * record.lifecycle of the gestionale (record-lifecycle.ts), for clients and requests:
 * - archive: reversible, admin or the referent (crm); never the scout;
 * - remove: reversible removal from the lists, admin only and with explicit confirmation;
 * - restore: back to the active lists.
 * Records, links and history are never deleted, anonymized or rewritten.
 */
final class SetRecordLifecycle
{
    public const MODES = ['archive', 'remove', 'restore'];

    public function __construct(private readonly Audit $audit) {}

    public function handle(AgencyMembership $actor, ClientProfile|PropertyRequest $record, string $mode, bool $confirmed = false): Model
    {
        if (! in_array($mode, self::MODES, true)) {
            throw new CommandRejected('Operazione sulla scheda non valida.', 400, 'mode');
        }

        $canManage = $actor->isActive() && (int) $record->agency_id === (int) $actor->agency_id
            && ($actor->isAdmin() || ($actor->role === 'crm' && (int) $record->agent_user_id === (int) $actor->user_id));
        if (! $canManage) {
            throw new CommandRejected('Scheda non accessibile con questo ruolo.', 403);
        }
        if ($mode === 'remove' && (! $actor->isAdmin() || ! $confirmed)) {
            throw new CommandRejected('La rimozione dalla lista richiede la conferma del Responsabile.', 403);
        }

        return DB::transaction(function () use ($actor, $record, $mode) {
            $before = $record->lifecycle_state;

            $record->forceFill($mode === 'restore'
                ? ['lifecycle_state' => null, 'lifecycle_at' => null, 'lifecycle_by_user_id' => null, 'lifecycle_reason' => null]
                : [
                    'lifecycle_state' => $mode === 'remove' ? 'removed' : 'archived',
                    'lifecycle_at' => now(),
                    'lifecycle_by_user_id' => $actor->user_id,
                    'lifecycle_reason' => $mode === 'remove' ? 'Rimozione reversibile dalla lista' : 'Archiviazione reversibile',
                ])->save();

            $this->audit->record('record.lifecycle', $record, [
                'mode' => $mode,
                'before' => $before,
                'after' => $record->lifecycle_state,
            ], $record->lifecycle_reason);

            return $record;
        });
    }
}
