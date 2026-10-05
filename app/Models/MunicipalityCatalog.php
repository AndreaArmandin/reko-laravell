<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pointer from a municipality to the catalog release currently in force.
 */
class MunicipalityCatalog extends Model
{
    protected $table = 'municipality_catalogs';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'activated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Municipality, $this>
     */
    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    /**
     * @return BelongsTo<CatalogRelease, $this>
     */
    public function catalogRelease(): BelongsTo
    {
        return $this->belongsTo(CatalogRelease::class);
    }
}
