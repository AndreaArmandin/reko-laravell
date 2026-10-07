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
        // Operatore 1 (scout) non ha la sezione Immobili a portafoglio: engine.ts:58 gli svuota l'elenco, engine.ts:111 blocca property.*
        return in_array($this->membership($user)?->role, ['admin', 'crm'], true);
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
