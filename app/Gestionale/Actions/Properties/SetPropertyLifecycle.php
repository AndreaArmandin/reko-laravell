<?php

namespace App\Gestionale\Actions\Properties;

use App\Gestionale\Audit;
use App\Gestionale\CommandRejected;
use App\Gestionale\Properties\PropertyMatcher;
use App\Models\AgencyMembership;
use App\Models\Property;
use Illuminate\Support\Facades\DB;

final class SetPropertyLifecycle
{
    public function __construct(private readonly Audit $audit, private readonly PropertyMatcher $matcher) {}

    public function handle(AgencyMembership $actor, Property $property, string $mode): Property
    {
        if (! $actor->isActive() || (int) $actor->agency_id !== (int) $property->agency_id
            || ! in_array($actor->role, ['admin', 'crm', 'scout'], true)
            || ($actor->role === 'crm' && (int) $property->agent_user_id !== (int) $actor->user_id)) {
            throw new CommandRejected('Immobile non accessibile con questo ruolo.', 403);
        }
        if (! in_array($mode, ['archive', 'restore'], true)) throw new CommandRejected('Operazione non valida.', 400);

        return DB::transaction(function () use ($actor, $property, $mode) {
            $before = $property->lifecycle_state;
            $property->forceFill($mode === 'restore'
                ? ['lifecycle_state' => null, 'lifecycle_at' => null, 'lifecycle_by_user_id' => null, 'lifecycle_reason' => null]
                : ['lifecycle_state' => 'archived', 'lifecycle_at' => now(), 'lifecycle_by_user_id' => $actor->user_id, 'lifecycle_reason' => 'Archiviazione reversibile'])->save();
            $this->matcher->refreshProperty($property);
            $this->audit->record('property.lifecycle', $property, ['mode' => $mode, 'before' => $before, 'after' => $property->lifecycle_state]);
            return $property->refresh();
        });
    }
}
