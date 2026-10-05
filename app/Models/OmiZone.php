<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * OMI zone inside a municipality.
 */
class OmiZone extends Model
{
    protected $table = 'omi_zones';

    protected $guarded = ['id'];

    /**
     * @return BelongsTo<Municipality, $this>
     */
    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    /**
     * @return HasMany<OmiQuotation, $this>
     */
    public function quotations(): HasMany
    {
        return $this->hasMany(OmiQuotation::class);
    }
}
