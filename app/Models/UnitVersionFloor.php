<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Floor row belonging to one cadastral unit version.
 */
class UnitVersionFloor extends Model
{
    protected $table = 'unit_version_floors';

    protected $guarded = ['id'];

    /**
     * @return BelongsTo<CadastralUnitVersion, $this>
     */
    public function cadastralUnitVersion(): BelongsTo
    {
        return $this->belongsTo(CadastralUnitVersion::class);
    }
}
