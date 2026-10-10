<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Scheda di censimento dell'agenzia per una unità catastale (DemoUnit del gestionale originale):
 * dati della fonte Sister, stato, ubicazione corretta a mano. Una per agenzia e unità.
 */
class AgencyUnitObservation extends Model
{
    use BelongsToAgency;

    protected $table = 'agency_unit_observations';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'observed_on' => 'date',
            'data' => 'array',
            'situation_date' => 'date:Y-m-d',
            'last_verified' => 'date:Y-m-d',
            'removed' => 'array',
            'subject_holding' => 'array',
            'income' => 'float',
            'consistency_value' => 'float',
        ];
    }

    /**
     * @return BelongsTo<Agency, $this>
     */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    /**
     * @return BelongsTo<CadastralUnit, $this>
     */
    public function cadastralUnit(): BelongsTo
    {
        return $this->belongsTo(CadastralUnit::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
