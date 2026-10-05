<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Italian region reference.
 */
class TerritorialRegion extends Model
{
    protected $table = 'territorial_regions';

    protected $guarded = ['id'];

    /**
     * @return HasMany<TerritorialProvince, $this>
     */
    public function provinces(): HasMany
    {
        return $this->hasMany(TerritorialProvince::class);
    }
}
