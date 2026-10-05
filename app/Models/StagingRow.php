<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Raw imported row payload.
 */
class StagingRow extends Model
{
    protected $table = 'staging_rows';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'parsed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ImportChunk, $this>
     */
    public function importChunk(): BelongsTo
    {
        return $this->belongsTo(ImportChunk::class);
    }

    /**
     * @return BelongsTo<Agency, $this>
     */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }
}
