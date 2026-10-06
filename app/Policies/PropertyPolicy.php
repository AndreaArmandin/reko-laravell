<?php

namespace App\Policies;

use App\Gestionale\CurrentAgency;
use App\Models\AgencyMembership;
use App\Models\Property;
use App\Models\User;

class PropertyPolicy
{
    public function __construct(private readonly CurrentAgency $current) {}

    public function viewAny(User $user): bool
    {
        return in_array($this->membership($user)?->role, ['admin', 'crm', 'scout'], true);
    }

    public function view(User $user, Property $property): bool
    {
        $membership = $this->membership($user);
        if (! $membership || (int) $property->agency_id !== (int) $membership->agency_id) {
            return false;
        }

        return match ($membership->role) {
            'admin' => true,
            'crm' => (int) $property->agent_user_id === (int) $membership->user_id,
            'scout' => (int) $property->agent_user_id === (int) $membership->user_id
                || (int) $property->acquired_by_user_id === (int) $membership->user_id
                || in_array((int) $membership->user_id, array_map('intval', $property->assigned_scout_user_ids ?? []), true),
            default => false,
        };
    }

    public function create(User $user): bool
    {
        return in_array($this->membership($user)?->role, ['admin', 'crm'], true);
    }

    public function update(User $user, Property $property): bool
    {
        $membership = $this->membership($user);
        return $this->view($user, $property) && in_array($membership?->role, ['admin', 'crm'], true)
            && $property->lifecycle_state !== 'removed';
    }

    public function updateScoutPrice(User $user, Property $property): bool
    {
        return $this->view($user, $property) && $this->membership($user)?->role === 'scout'
            && $property->lifecycle_state !== 'removed';
    }

    public function archive(User $user, Property $property): bool
    {
        return $this->view($user, $property);
    }

    public function remove(User $user, Property $property): bool
    {
        return $this->membership($user)?->role === 'admin' && $this->view($user, $property);
    }

    private function membership(User $user): ?AgencyMembership
    {
        $membership = $this->current->membership();

        return $membership !== null && $membership->isActive() && (int) $membership->user_id === (int) $user->getKey()
            ? $membership
            : null;
    }
}
