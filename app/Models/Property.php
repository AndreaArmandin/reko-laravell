<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Agency listing. Asking price and location stay null until known.
 */
class Property extends Model
{
    protected $table = 'properties';

    protected $guarded = ['id'];

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
}
