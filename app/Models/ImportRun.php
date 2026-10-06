<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int|null $municipality_id
 * @property int|null $catalog_release_id
 * @property string $source_name
 * @property string $original_filename
 * @property string|null $private_path
 * @property string $status
 * @property int $rows_read
 * @property int $rows_imported
 * @property int $rows_rejected
 * @property array<string, mixed>|null $summary
 */
class ImportRun extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime', 'summary' => 'array'];
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
    public function release(): BelongsTo
    {
        return $this->belongsTo(CatalogRelease::class, 'catalog_release_id');
    }

    /**
     * @return HasMany<ImportIssue, $this>
     */
    public function issues(): HasMany
    {
        return $this->hasMany(ImportIssue::class);
    }
}
