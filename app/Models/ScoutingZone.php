<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Agency scouting area. Boundary is a nullable MultiPolygon.
 */
class ScoutingZone extends Model
{
    protected $table = 'scouting_zones';

    protected $guarded = ['id'];

    /**
     * @return BelongsTo<Agency, $this>
     */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    /**
     * @return HasMany<ScoutingZoneAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(ScoutingZoneAssignment::class);
    }
}
