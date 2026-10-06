<?php

namespace App\Policies;

use App\Gestionale\CurrentAgency;
use App\Models\AgencyMembership;
use App\Models\Contact;
use App\Models\User;

/**
 * Gestionale rules for contacts (permissions.ts, engine.ts):
 * - admin (agency Responsabile): everything in its agency;
 * - crm (Segreteria): only clients it is the referent of; cannot edit owner identity;
 * - scout: no client access (engine.ts blocks client.*); owners arrive with scouting.
 * Always within the current agency and with an active membership. users.is_admin
 * (platform admin) grants nothing here.
 */
class ContactPolicy
{
    public function __construct(private readonly CurrentAgency $current) {}

    public function viewAny(User $user): bool
    {
        return in_array($this->membership($user)?->role, ['admin', 'crm'], true);
    }

    public function view(User $user, Contact $contact): bool
    {
        $membership = $this->membershipFor($user, $contact);

        return match ($membership?->role) {
            'admin' => true,
            'crm' => $this->isReferent($membership, $contact),
            default => false,
        };
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Contact $contact): bool
    {
        return ! $contact->isRemoved() && $this->view($user, $contact);
    }

    /** Tax code, birth details, owner notes and tags: owner.edit, admin only (engine.ts owner.save). */
    public function updateOwnerIdentity(User $user, Contact $contact): bool
    {
        return (bool) $this->membershipFor($user, $contact)?->allows('owner.edit');
    }

    /** Reversible archive / restore of the client card (record.lifecycle archive|restore). */
    public function archive(User $user, Contact $contact): bool
    {
        return $this->view($user, $contact);
    }

    /** Removal from the lists: admin only, with confirmation (record.lifecycle remove). */
    public function remove(User $user, Contact $contact): bool
    {
        return $this->membershipFor($user, $contact)?->role === 'admin';
    }

    /** No physical deletion in the gestionale. */
    public function delete(User $user, Contact $contact): bool
    {
        return false;
    }

    public function forceDelete(User $user, Contact $contact): bool
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

    private function membershipFor(User $user, Contact $contact): ?AgencyMembership
    {
        $membership = $this->membership($user);

        return $membership !== null && (int) $membership->agency_id === (int) $contact->agency_id ? $membership : null;
    }

    private function isReferent(AgencyMembership $membership, Contact $contact): bool
    {
        return $contact->clientProfile()->where('agent_user_id', $membership->user_id)->exists();
    }
}
