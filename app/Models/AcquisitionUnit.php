<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cadastral unit linked to an acquisition.
 */
class AcquisitionUnit extends Model
{
    use BelongsToAgency;

    protected $table = 'acquisition_units';

    protected $guarded = ['id'];

    /**
     * @return BelongsTo<Agency, $this>
     */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    /**
     * @return BelongsTo<Acquisition, $this>
     */
    public function acquisition(): BelongsTo
    {
        return $this->belongsTo(Acquisition::class);
    }

    /**
     * @return BelongsTo<CadastralUnit, $this>
     */
    public function cadastralUnit(): BelongsTo
    {
        return $this->belongsTo(CadastralUnit::class);
    }
}
