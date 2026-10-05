<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Origin of an import. agency_id is null for a shared source.
 */
class DataSource extends Model
{
    protected $table = 'data_sources';

    protected $guarded = ['id'];

    /**
     * @return BelongsTo<Agency, $this>
     */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    /**
     * @return HasMany<SourceFile, $this>
     */
    public function sourceFiles(): HasMany
    {
        return $this->hasMany(SourceFile::class);
    }
}
