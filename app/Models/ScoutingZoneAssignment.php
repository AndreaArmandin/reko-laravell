<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * User assigned to a scouting zone.
 */
class ScoutingZoneAssignment extends Model
{
    use BelongsToAgency;

    protected $table = 'scouting_zone_assignments';

    protected $guarded = ['id'];

    /**
     * @return BelongsTo<Agency, $this>
     */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    /**
     * @return BelongsTo<ScoutingZone, $this>
     */
    public function scoutingZone(): BelongsTo
    {
        return $this->belongsTo(ScoutingZone::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
