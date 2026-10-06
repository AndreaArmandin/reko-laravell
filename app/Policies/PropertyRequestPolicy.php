<?php

namespace App\Policies;

use App\Gestionale\CurrentAgency;
use App\Models\AgencyMembership;
use App\Models\Contact;
use App\Models\PropertyRequest;
use App\Models\User;

/**
 * Gestionale rules for client requests (permissions.ts visibleEntities, engine.ts canManage):
 * - admin: every request of its agency;
 * - crm: only the requests it is the referent of (the referent of the client);
 * - scout: none.
 * A removed request is read-only until restored. users.is_admin grants nothing here.
 */
class PropertyRequestPolicy
{
    public function __construct(private readonly CurrentAgency $current) {}

    public function viewAny(User $user): bool
    {
        return in_array($this->membership($user)?->role, ['admin', 'crm'], true);
    }

    public function view(User $user, PropertyRequest $request): bool
    {
        $membership = $this->membership($user);
        if ($membership === null || (int) $membership->agency_id !== (int) $request->agency_id) {
            return false;
        }

        return match ($membership->role) {
            'admin' => true,
            'crm' => (int) $request->agent_user_id === (int) $membership->user_id,
            default => false,
        };
    }

    /** A request is opened for a client the user can manage. */
    public function create(User $user, ?Contact $client = null): bool
    {
        return $this->viewAny($user) && ($client === null || $user->can('update', $client));
    }

    public function update(User $user, PropertyRequest $request): bool
    {
        return $request->lifecycle_state !== 'removed' && $this->view($user, $request);
    }

    public function archive(User $user, PropertyRequest $request): bool
    {
        return $this->view($user, $request);
    }

    public function remove(User $user, PropertyRequest $request): bool
    {
        return $this->view($user, $request) && $this->membership($user)?->role === 'admin';
    }

    public function delete(User $user, PropertyRequest $request): bool
    {
        return false;
    }

    public function forceDelete(User $user, PropertyRequest $request): bool
    {
        return false;
    }

    private function membership(User $user): ?AgencyMembership
    {
        $membership = $this->current->membership();

        return $membership !== null && (int) $membership->user_id === (int) $user->getKey() && $membership->isActive()
            ? $membership
            : null;
    }
}
