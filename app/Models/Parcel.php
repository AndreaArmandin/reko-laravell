<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Stable particella identity. Measures live on parcel versions.
 */
class Parcel extends Model
{
    protected $table = 'parcels';

    protected $guarded = ['id'];

    /**
     * @return BelongsTo<Municipality, $this>
     */
    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    /**
     * @return HasMany<ParcelVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(ParcelVersion::class);
    }

    /**
     * @return HasMany<CadastralUnit, $this>
     */
    public function cadastralUnits(): HasMany
    {
        return $this->hasMany(CadastralUnit::class);
    }

    /**
     * @return HasMany<ParcelSearchPoint, $this>
     */
    public function searchPoints(): HasMany
    {
        return $this->hasMany(ParcelSearchPoint::class);
    }

    /**
     * @return HasMany<ParcelHousingContext, $this>
     */
    public function housingContexts(): HasMany
    {
        return $this->hasMany(ParcelHousingContext::class);
    }
}
