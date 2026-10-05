<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Locality or frazione inside a municipality.
 */
class TerritorialLocality extends Model
{
    protected $table = 'territorial_localities';

    protected $guarded = ['id'];

    /**
     * @return BelongsTo<Municipality, $this>
     */
    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }
}
