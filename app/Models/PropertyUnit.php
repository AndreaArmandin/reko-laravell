<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cadastral unit attached to an agency property.
 */
class PropertyUnit extends Model
{
    use BelongsToAgency;

    protected $table = 'property_units';

    protected $guarded = ['id'];

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
     * @return BelongsTo<CadastralUnit, $this>
     */
    public function cadastralUnit(): BelongsTo
    {
        return $this->belongsTo(CadastralUnit::class);
    }
}
