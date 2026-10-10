<?php

namespace App\Gestionale\Activities;

use App\Models\Activity;
use App\Models\AgencyMembership;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Who sees, completes and edits an activity (permissions.ts canSeeActivity / canCompleteActivity /
 * canEditActivity), plus the optional permissions applied for real with AgencyMembership::allows().
 */
final class ActivityAccess
{
    /** @var array<int, ActivityLinks> */
    private array $links = [];

    public function linksOf(AgencyMembership $person): ActivityLinks
    {
        return $this->links[(int) $person->user_id] ??= new ActivityLinks($person);
    }

    /** SQL part of canSeeActivity: admin everything; others only what they created, own or were shared. */
    public function participantQuery(AgencyMembership $actor): Builder
    {
        $query = Activity::query()->where('activities.agency_id', $actor->agency_id);
        if ($actor->role !== 'admin') {
            $userId = $actor->user_id;
            $query->where(function (Builder $q) use ($userId) {
                $q->where('assigned_to_user_id', $userId)
                    ->orWhere('created_by_user_id', $userId)
                    ->orWhereHas('participants', fn (Builder $p) => $p->where('user_id', $userId));
            });
        }

        return $query;
    }

    public function canSee(AgencyMembership $actor, Activity $activity): bool
    {
        if (! $actor->isActive() || (int) $activity->agency_id !== (int) $actor->agency_id) {
            return false;
        }
        if ($actor->role === 'admin') {
            return true;
        }
        $userId = (int) $actor->user_id;
        $mine = (int) $activity->assigned_to_user_id === $userId || (int) $activity->created_by_user_id === $userId
            || $activity->participants()->where('user_id', $userId)->exists();

        return $mine && $this->linksOf($actor)->visible(self::linksOfActivity($activity));
    }

    /**
     * Activities the actor can see (canSeeActivity applied), optionally narrowed by a query callback.
     *
     * @param  (callable(Builder): mixed)|null  $constrain
     * @return Collection<int, Activity>
     */
    public function visible(AgencyMembership $actor, ?callable $constrain = null): Collection
    {
        if (! $actor->isActive()) {
            return new Collection;
        }
        $query = $this->participantQuery($actor);
        if ($constrain) {
            $constrain($query);
        }

        return $query->with(['units', 'participants', 'events.user'])->get()
            ->filter(fn (Activity $a) => $actor->role === 'admin' || $this->linksOf($actor)->visible(self::linksOfActivity($a)))
            ->values();
    }

    /** permissions.ts canCompleteActivity: the assignee or an administrator. */
    public function canComplete(AgencyMembership $actor, Activity $activity): bool
    {
        return $actor->isActive() && ($actor->role === 'admin' || (int) $activity->assigned_to_user_id === (int) $actor->user_id);
    }

    /** permissions.ts canEditActivity: admin always; assignee or creator until completed/confirmed. */
    public function canEdit(AgencyMembership $actor, Activity $activity): bool
    {
        return $actor->isActive() && ($actor->role === 'admin'
            || (! $activity->isDone() && $activity->outcome_confirmed_at === null
                && ((int) $activity->assigned_to_user_id === (int) $actor->user_id || (int) $activity->created_by_user_id === (int) $actor->user_id)));
    }

    /**
     * @return array{contact_id: ?int, property_request_id: ?int, property_id: ?int, owner_contact_id: ?int, parcel_id: ?int, unit_ids: list<int>}
     */
    public static function linksOfActivity(Activity $activity): array
    {
        return [
            'contact_id' => $activity->contact_id,
            'property_request_id' => $activity->property_request_id,
            'property_id' => $activity->property_id,
            'owner_contact_id' => $activity->owner_contact_id,
            'parcel_id' => $activity->parcel_id,
            'unit_ids' => $activity->units->pluck('cadastral_unit_id')->map(fn ($id) => (int) $id)->all(),
        ];
    }
}
