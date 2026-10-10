<?php

namespace App\Models;

use Database\Factories\AgencyMembershipFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Links a starter-kit user to an agency, with the gestionale role and optional permissions.
 * Agency roles (admin = Responsabile, scout = Agente acquisizioni, crm = Segreteria) are
 * unrelated to users.is_admin, which is the REKO platform administrator.
 */
class AgencyMembership extends Model
{
    /** @use HasFactory<AgencyMembershipFactory> */
    use HasFactory;

    public const ROLES = ['admin', 'scout', 'crm'];

    /** permissions.ts optionalPermissions */
    public const OPTIONAL_PERMISSIONS = ['sister.import', 'owner.edit', 'activities.assign', 'activities.share', 'exports'];

    /** permissions.ts defaultOptionalPermissions: used while permissions is NULL */
    public const DEFAULT_PERMISSIONS = ['activities.assign', 'activities.share', 'exports'];

    protected $table = 'agency_memberships';

    protected $guarded = ['id'];

    /**
     * Role of the account when a work profile narrows it for this session (permissions.ts
     * actorWithProfile): only an admin can pick crm or scout. `role` then holds the profile,
     * in memory only (never persisted); null when no profile is applied.
     */
    public ?string $realRole = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'deactivated_at' => 'datetime',
            'permissions' => 'array',
            'catalog_package' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Agency, $this>
     */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->deactivated_at === null;
    }

    /** The role the account really has, ignoring the work profile of the session. */
    public function actualRole(): string
    {
        return $this->realRole ?? (string) $this->role;
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Port of permissions.ts allowed(): admin has everything; owner.edit is admin-only;
     * sister.import is never granted to crm and only explicitly to scout; the other
     * optional permissions follow the explicit list, or the defaults while it is NULL.
     */
    public function allows(string $permission): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        if ($this->role === 'admin') {
            return true;
        }

        if ($permission === 'owner.edit' || ($this->role === 'crm' && $permission === 'sister.import')) {
            return false;
        }

        if ($permission === 'sister.import') {
            return in_array($permission, $this->permissions ?? [], true);
        }

        return in_array($permission, $this->permissions ?? self::DEFAULT_PERMISSIONS, true);
    }

    /**
     * @param  Builder<AgencyMembership>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull($query->qualifyColumn('deactivated_at'));
    }
}
