<?php

namespace App\Gestionale\Actions\Properties;

use App\Gestionale\Audit;
use App\Gestionale\CommandRejected;
use App\Gestionale\Commands;
use App\Gestionale\Properties\PropertyMatcher;
use App\Models\AgencyMembership;
use App\Models\Property;
use Illuminate\Support\Facades\DB;

/**
 * record.lifecycle for a property (record-lifecycle.ts setRecordLifecycle):
 * - archive: reversible, admin or the referent (crm); never the scout;
 * - remove ("Rimuovi dalle liste"): reversible removal from the lists, admin only and with explicit confirmation;
 * - restore: back to the active lists ("Ripristina · annulla archiviazione").
 * Records, links, scores and history are never deleted or rewritten.
 */
final class SetPropertyLifecycle
{
    public const MODES = ['archive', 'remove', 'restore'];

    public function __construct(private readonly Audit $audit, private readonly PropertyMatcher $matcher) {}

    public function handle(AgencyMembership $actor, Property $property, string $mode, bool $confirmed = false): Property
    {
        if (! in_array($mode, self::MODES, true)) {
            throw new CommandRejected('Operazione sulla scheda non valida.', 400, 'mode');
        }
        if (! $actor->isActive() || (int) $actor->agency_id !== (int) $property->agency_id
            || ! in_array($actor->role, ['admin', 'crm'], true)
            || ($actor->role === 'crm' && (int) $property->agent_user_id !== (int) $actor->user_id)) {
            throw new CommandRejected('Scheda non accessibile con questo ruolo.', 403);
        }
        if ($mode === 'remove' && (! $actor->isAdmin() || ! $confirmed)) {
            throw new CommandRejected('La rimozione dalla lista richiede la conferma del Responsabile.', 403);
        }

        return DB::transaction(function () use ($actor, $property, $mode) {
            $before = $property->lifecycle_state;
            $property->forceFill($mode === 'restore'
                ? ['lifecycle_state' => null, 'lifecycle_at' => null, 'lifecycle_by_user_id' => null, 'lifecycle_reason' => null]
                : [
                    'lifecycle_state' => $mode === 'remove' ? 'removed' : 'archived',
                    'lifecycle_at' => now(),
                    'lifecycle_by_user_id' => $actor->user_id,
                    'lifecycle_reason' => $mode === 'remove' ? 'Rimozione reversibile dalla lista' : 'Archiviazione reversibile',
                ])->save();
            $this->matcher->refreshProperty($property);
            $this->audit->record('property.lifecycle', $property, ['mode' => $mode, 'before' => $before, 'after' => $property->lifecycle_state], $property->lifecycle_reason);

            return $property->refresh();
        });
    }
}
