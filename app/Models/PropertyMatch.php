<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Match between a property and a request. Score stays null until computed.
 */
class PropertyMatch extends Model
{
    use BelongsToAgency;

    protected $table = 'property_matches';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['result' => 'array', 'visit_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Agency, $this>
     */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * @return BelongsTo<PropertyRequest, $this>
     */
    public function propertyRequest(): BelongsTo
    {
        return $this->belongsTo(PropertyRequest::class);
    }
}
