<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Scouting task for a user.
 */
class ScoutingAssignment extends Model
{
    protected $table = 'scouting_assignments';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_on' => 'date',
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

    /**
     * @return BelongsTo<CadastralUnit, $this>
     */
    public function cadastralUnit(): BelongsTo
    {
        return $this->belongsTo(CadastralUnit::class);
    }

    /**
     * @return BelongsTo<Parcel, $this>
     */
    public function parcel(): BelongsTo
    {
        return $this->belongsTo(Parcel::class);
    }

    /**
     * @return BelongsTo<ScoutingZone, $this>
     */
    public function scoutingZone(): BelongsTo
    {
        return $this->belongsTo(ScoutingZone::class);
    }
}
