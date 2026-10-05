<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Building-to-parcel link inside one catalog release.
 */
class BuildingParcelLink extends Model
{
    protected $table = 'building_parcel_links';

    protected $guarded = ['id'];

    /**
     * @return BelongsTo<CatalogRelease, $this>
     */
    public function catalogRelease(): BelongsTo
    {
        return $this->belongsTo(CatalogRelease::class);
    }

    /**
     * @return BelongsTo<Building, $this>
     */
    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    /**
     * @return BelongsTo<Parcel, $this>
     */
    public function parcel(): BelongsTo
    {
        return $this->belongsTo(Parcel::class);
    }
}
