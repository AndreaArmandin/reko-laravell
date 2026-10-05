<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Unità immobiliare attributes for one catalog release.
 */
class CadastralUnitVersion extends Model
{
    protected $table = 'cadastral_unit_versions';

    protected $guarded = ['id'];

    /**
     * @return BelongsTo<CadastralUnit, $this>
     */
    public function cadastralUnit(): BelongsTo
    {
        return $this->belongsTo(CadastralUnit::class);
    }

    /**
     * @return BelongsTo<CatalogRelease, $this>
     */
    public function catalogRelease(): BelongsTo
    {
        return $this->belongsTo(CatalogRelease::class);
    }

    /**
     * @return HasMany<UnitVersionFloor, $this>
     */
    public function floors(): HasMany
    {
        return $this->hasMany(UnitVersionFloor::class);
    }
}
