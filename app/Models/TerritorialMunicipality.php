<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Comune of the national ISTAT list (reference data for suggestions, not a Comune of the platform).
 */
class TerritorialMunicipality extends Model
{
    protected $table = 'territorial_municipalities';

    protected $guarded = ['id'];

    /**
     * @return BelongsTo<TerritorialProvince, $this>
     */
    public function province(): BelongsTo
    {
        return $this->belongsTo(TerritorialProvince::class, 'territorial_province_id');
    }
}
