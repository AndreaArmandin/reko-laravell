<?php

namespace App\Policies;

use App\Gestionale\Activities\ActivityAccess;
use App\Gestionale\CurrentAgency;
use App\Models\Activity;
use App\Models\AgencyMembership;
use App\Models\User;

/**
 * Activities follow permissions.ts: canSeeActivity (admin all; others what they created, own or were
 * shared, with every link visible to them), canEditActivity, canCompleteActivity (assignee or admin).
 * assign / share are optional permissions applied with AgencyMembership::allows().
 */
class ActivityPolicy
{
    public function __construct(
        private readonly CurrentAgency $current,
        private readonly ActivityAccess $access,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->membership($user) !== null;
    }

    public function view(User $user, Activity $activity): bool
    {
        $membership = $this->membership($user);

        return $membership !== null && $this->access->canSee($membership, $activity);
    }

    public function create(User $user): bool
    {
        return $this->membership($user) !== null;
    }

    /** canEditActivity: admin always; assignee or creator until the activity is completed/confirmed. */
    public function update(User $user, Activity $activity): bool
    {
        $membership = $this->membership($user);

        return $membership !== null && $this->access->canSee($membership, $activity) && $this->access->canEdit($membership, $activity);
    }

    /** canCompleteActivity: only the assignee or an administrator (Accetta, Rinvia, Chiedi chiarimenti, Annulla, Completa). */
    public function complete(User $user, Activity $activity): bool
    {
        $membership = $this->membership($user);

        return $membership !== null && $this->access->canSee($membership, $activity) && $this->access->canComplete($membership, $activity);
    }

    /** activities.assign: give a new activity to someone else. */
    public function assign(User $user): bool
    {
        return $this->membership($user)?->allows('activities.assign') === true;
    }

    /** activities.share: share an activity with other people. */
    public function share(User $user): bool
    {
        return $this->membership($user)?->allows('activities.share') === true;
    }

    private function membership(User $user): ?AgencyMembership
    {
        $membership = $this->current->membership();

        return $membership !== null && $membership->isActive() && (int) $membership->user_id === (int) $user->getKey()
            ? $membership
            : null;
    }
}
