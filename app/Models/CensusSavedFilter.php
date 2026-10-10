<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;

/**
 * Combinazione di filtri dell'Archivio catastale salvata da un operatore (SavedCensusFilter).
 */
class CensusSavedFilter extends Model
{
    use BelongsToAgency;

    protected $table = 'census_saved_filters';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['filters' => 'array'];
    }
}
