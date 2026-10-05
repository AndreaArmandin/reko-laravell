<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A published snapshot of cadastral source data.
 */
class CatalogRelease extends Model
{
    protected $table = 'catalog_releases';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'released_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Municipality, $this>
     */
    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    /**
     * @return HasMany<MunicipalityCatalog, $this>
     */
    public function municipalityCatalogs(): HasMany
    {
        return $this->hasMany(MunicipalityCatalog::class);
    }

    /**
     * @return HasMany<ParcelVersion, $this>
     */
    public function parcelVersions(): HasMany
    {
        return $this->hasMany(ParcelVersion::class);
    }

    /**
     * @return HasMany<CadastralUnitVersion, $this>
     */
    public function cadastralUnitVersions(): HasMany
    {
        return $this->hasMany(CadastralUnitVersion::class);
    }

    /**
     * @return HasMany<BuildingVersion, $this>
     */
    public function buildingVersions(): HasMany
    {
        return $this->hasMany(BuildingVersion::class);
    }
}
