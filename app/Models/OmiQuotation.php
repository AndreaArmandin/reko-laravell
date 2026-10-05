<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * OMI quotation range. Min and max stay null when unpublished.
 */
class OmiQuotation extends Model
{
    protected $table = 'omi_quotations';

    protected $guarded = ['id'];

    /**
     * @return BelongsTo<OmiZone, $this>
     */
    public function omiZone(): BelongsTo
    {
        return $this->belongsTo(OmiZone::class);
    }
}
