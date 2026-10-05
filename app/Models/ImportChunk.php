<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A slice of an import batch.
 */
class ImportChunk extends Model
{
    protected $table = 'import_chunks';

    protected $guarded = ['id'];

    /**
     * @return BelongsTo<ImportBatch, $this>
     */
    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class);
    }

    /**
     * @return BelongsTo<Agency, $this>
     */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    /**
     * @return HasMany<StagingRow, $this>
     */
    public function rows(): HasMany
    {
        return $this->hasMany(StagingRow::class);
    }
}
