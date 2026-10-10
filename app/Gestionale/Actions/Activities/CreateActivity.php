<?php

namespace App\Gestionale\Actions\Activities;

use App\Models\Activity;
use App\Models\AgencyMembership;

/**
 * New activity of the current agency, for every part of the gestionale that needs one
 * (client/request/property cards, matches, goals, the agenda itself). Same rules as the agenda:
 * assignment and sharing follow activities.assign / activities.share, links must be visible to the
 * actor and to the assignee, the assignee is notified.
 *
 *   app(CreateActivity::class)->handle($membership, [
 *       'type' => 'Visita', 'title' => 'Visita: Via Roma 1', 'due_at' => '2026-10-12 15:00',
 *       'assigned_to_user_id' => $userId,            // default: the actor
 *       'property_request_id' => $request->id, 'property_id' => $property->id,   // links
 *       'contact_id' => ..., 'owner_contact_id' => ..., 'parcel_id' => ..., 'unit_ids' => [...],
 *       'notes' => '...', 'priority' => 'Normale', 'metadata' => [...],
 *   ]);
 *
 * See SaveActivity for the full list of keys. A new activity is "Da svolgere" unless done=true
 * (then outcome and notes are expected, as in "Registra attività").
 */
final class CreateActivity
{
    public function __construct(private readonly SaveActivity $save) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(AgencyMembership $actor, array $input): Activity
    {
        unset($input['expected_revision']);

        return $this->save->handle($actor, null, $input);
    }
}
