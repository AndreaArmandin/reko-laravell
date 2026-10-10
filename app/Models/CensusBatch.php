<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una importazione da SISTER dell'archivio catastale (CensusBatch del gestionale):
 * impronta del testo, conteggi e righe scartate. Si può ripetere senza creare doppioni.
 */
class CensusBatch extends Model
{
    use BelongsToAgency;

    protected $table = 'census_batches';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['source_date' => 'date:Y-m-d', 'summary' => 'array', 'issues' => 'array'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
