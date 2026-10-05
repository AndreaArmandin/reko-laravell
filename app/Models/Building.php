<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Stable fabbricato identity.
 */
class Building extends Model
{
    protected $table = 'buildings';

    protected $guarded = ['id'];

    /**
     * @return BelongsTo<Municipality, $this>
     */
    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    /**
     * @return HasMany<BuildingVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(BuildingVersion::class);
    }
}
