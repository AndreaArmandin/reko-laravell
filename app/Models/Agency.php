<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Real-estate agency. Operational rows reference this via agency_id.
 */
class Agency extends Model
{
    protected $table = 'agencies';

    protected $guarded = ['id'];

    /**
     * @return HasMany<AgencyMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(AgencyMembership::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'agency_memberships')->withPivot('role')->withTimestamps();
    }

    /**
     * @return HasMany<AgencyRequest, $this>
     */
    public function requests(): HasMany
    {
        return $this->hasMany(AgencyRequest::class);
    }
}
