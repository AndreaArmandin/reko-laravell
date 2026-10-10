<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Agency scouting area. Boundary is a nullable MultiPolygon.
 */
class ScoutingZone extends Model
{
    use BelongsToAgency;

    protected $table = 'scouting_zones';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['municipalities' => 'array', 'plan' => 'array', 'starts_on' => 'date', 'updated_at' => 'immutable_datetime'];
    }

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
