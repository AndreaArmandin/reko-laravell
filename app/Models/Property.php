<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Agency listing. Asking price and location stay null until known.
 */
class Property extends Model
{
    use BelongsToAgency;

    public const STATUSES = ['Attivo', 'Non attivo', 'Scaduto', 'In trattativa', 'Locato', 'Venduto'];

    protected $table = 'properties';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'features' => 'array',
            'mandate' => 'array',
            'publication' => 'array',
            'assigned_scout_user_ids' => 'array',
            'lifecycle_at' => 'datetime',
            'acquired_at' => 'datetime',
            'updated_at' => 'immutable_datetime',
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
     * @return BelongsTo<Municipality, $this>
     */
    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    /**
     * @return HasMany<PropertyUnit, $this>
     */
    public function units(): HasMany
    {
        return $this->hasMany(PropertyUnit::class);
    }

    /**
     * @return HasMany<PropertyContact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(PropertyContact::class);
    }

    /**
     * @return HasMany<PropertyMatch, $this>
     */
    public function matches(): HasMany
    {
        return $this->hasMany(PropertyMatch::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    public function scopeVisibleTo(\Illuminate\Database\Eloquent\Builder $query, ?AgencyMembership $membership): void
    {
        if (! $membership?->isActive()) {
            $query->whereRaw('false');

            return;
        }

        $query->where($query->qualifyColumn('agency_id'), $membership->agency_id);

        if ($membership->role === 'admin') {
            return;
        }

        if ($membership->role === 'crm') {
            $query->where($query->qualifyColumn('agent_user_id'), $membership->user_id);

            return;
        }

        $query->where(fn ($visible) => $visible
            ->where($query->qualifyColumn('agent_user_id'), $membership->user_id)
            ->orWhere($query->qualifyColumn('acquired_by_user_id'), $membership->user_id)
            ->orWhereJsonContains($query->qualifyColumn('assigned_scout_user_ids'), (int) $membership->user_id));
    }
}
