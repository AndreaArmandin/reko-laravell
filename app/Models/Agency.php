<?php

namespace App\Models;

use Database\Factories\AgencyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Real-estate agency. Operational rows reference this via agency_id (RESTRICT):
 * an agency with data is deactivated, never deleted.
 */
class Agency extends Model
{
    /** @use HasFactory<AgencyFactory> */
    use HasFactory;

    protected $table = 'agencies';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'deactivated_at' => 'datetime',
        ];
    }

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

    public function isActive(): bool
    {
        return $this->deactivated_at === null;
    }

    /**
     * @param  Builder<Agency>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull($query->qualifyColumn('deactivated_at'));
    }
}
