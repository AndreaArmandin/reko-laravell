<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Comune, keyed by the Belfiore cadastral code.
 */
class Municipality extends Model
{
    protected $table = 'municipalities';

    protected $guarded = ['id'];

    /**
     * @return BelongsTo<TerritorialProvince, $this>
     */
    public function province(): BelongsTo
    {
        return $this->belongsTo(TerritorialProvince::class, 'territorial_province_id');
    }

    /**
     * @return HasOne<MunicipalityCatalog, $this>
     */
    public function catalog(): HasOne
    {
        return $this->hasOne(MunicipalityCatalog::class);
    }

    /**
     * @return HasMany<Parcel, $this>
     */
    public function parcels(): HasMany
    {
        return $this->hasMany(Parcel::class);
    }

    /**
     * Units reach their comune through the parcel.
     *
     * @return HasManyThrough<CadastralUnit, Parcel, $this>
     */
    public function cadastralUnits(): HasManyThrough
    {
        return $this->hasManyThrough(CadastralUnit::class, Parcel::class);
    }

    /**
     * @return HasMany<CatalogRelease, $this>
     */
    public function catalogReleases(): HasMany
    {
        return $this->hasMany(CatalogRelease::class);
    }

    /**
     * @return HasMany<Building, $this>
     */
    public function buildings(): HasMany
    {
        return $this->hasMany(Building::class);
    }
}
