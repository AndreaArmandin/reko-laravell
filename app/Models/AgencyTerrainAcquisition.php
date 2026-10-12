<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;

class AgencyTerrainAcquisition extends Model
{
    use BelongsToAgency;

    protected $table = 'agency_terrain_acquisitions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['acquired_at' => 'datetime', 'area_sqm' => 'decimal:2'];
    }
}
