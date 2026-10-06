<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Map from a legacy system key (e.g. gestionale "c-123") to a REKO row of the same agency.
 */
class LegacyEntityRef extends Model
{
    use BelongsToAgency;

    protected $table = 'legacy_entity_refs';

    protected $guarded = ['id'];

    /**
     * @return MorphTo<Model, $this>
     */
    public function entity(): MorphTo
    {
        return $this->morphTo();
    }
}
