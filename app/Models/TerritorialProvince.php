<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Italian province reference.
 */
class TerritorialProvince extends Model
{
    protected $table = 'territorial_provinces';

    protected $guarded = ['id'];

    /**
     * @return BelongsTo<TerritorialRegion, $this>
     */
    public function region(): BelongsTo
    {
        return $this->belongsTo(TerritorialRegion::class, 'territorial_region_id');
    }

    /**
     * @return HasMany<Municipality, $this>
     */
    public function municipalities(): HasMany
    {
        return $this->hasMany(Municipality::class);
    }
}
