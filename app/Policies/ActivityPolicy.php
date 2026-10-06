<?php

namespace App\Policies;

use App\Gestionale\CurrentAgency;
use App\Models\Activity;
use App\Models\AgencyMembership;
use App\Models\User;

class ActivityPolicy
{
    public function __construct(private readonly CurrentAgency $current) {}

    public function viewAny(User $user): bool
    {
        return $this->membership($user) !== null;
    }

    public function view(User $user, Activity $activity): bool
    {
        $membership = $this->membership($user);
        if (! $membership || (int) $activity->agency_id !== (int) $membership->agency_id) {
            return false;
        }
        if ($membership->role === 'admin') {
            return true;
        }
        if ((int) $activity->assigned_to_user_id === (int) $membership->user_id
            || (int) $activity->created_by_user_id === (int) $membership->user_id
            || (int) $activity->user_id === (int) $membership->user_id) {
            return true;
        }

        if ($activity->participants()->where('user_id', $membership->user_id)->exists()) {
            return true;
        }

        if ($activity->visibility !== 'workflow' || $membership->role !== 'crm') {
            return false;
        }

        return ($activity->contact_id !== null && \App\Models\ClientProfile::query()
                ->where('contact_id', $activity->contact_id)->where('agent_user_id', $membership->user_id)->exists())
            || ($activity->property_request_id !== null && \App\Models\PropertyRequest::query()
                ->whereKey($activity->property_request_id)->where('agent_user_id', $membership->user_id)->exists())
            || ($activity->property_id !== null && \App\Models\Property::query()
                ->whereKey($activity->property_id)->where('agent_user_id', $membership->user_id)->exists());
    }

    public function create(User $user): bool
    {
        return $this->membership($user) !== null;
    }

    public function update(User $user, Activity $activity): bool
    {
        $membership = $this->membership($user);
        return $this->view($user, $activity) && ($membership?->role === 'admin'
            || ((int) $activity->assigned_to_user_id === (int) $membership?->user_id
                || (int) $activity->created_by_user_id === (int) $membership?->user_id)
                && ! in_array($activity->status, ['Completata', 'Annullata'], true));
    }

    private function membership(User $user): ?AgencyMembership
    {
        $membership = $this->current->membership();
        return $membership !== null && $membership->isActive() && (int) $membership->user_id === (int) $user->getKey()
            ? $membership
            : null;
    }
}
