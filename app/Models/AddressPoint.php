<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Address point. Location stays null when the source has no coordinate.
 */
class AddressPoint extends Model
{
    protected $table = 'address_points';

    protected $guarded = ['id'];

    /**
     * @return BelongsTo<Municipality, $this>
     */
    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }
}
