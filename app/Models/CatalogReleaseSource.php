<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Data source that contributed to a catalog release.
 */
class CatalogReleaseSource extends Model
{
    protected $table = 'catalog_release_sources';

    protected $guarded = ['id'];

    /**
     * @return BelongsTo<CatalogRelease, $this>
     */
    public function catalogRelease(): BelongsTo
    {
        return $this->belongsTo(CatalogRelease::class);
    }

    /**
     * @return BelongsTo<DataSource, $this>
     */
    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }

    /**
     * @return BelongsTo<SourceFile, $this>
     */
    public function sourceFile(): BelongsTo
    {
        return $this->belongsTo(SourceFile::class);
    }
}
