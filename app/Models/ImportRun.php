<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportRun extends Model
{
    protected $guarded = ['id'];

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    public function release(): BelongsTo
    {
        return $this->belongsTo(CatalogRelease::class, 'catalog_release_id');
    }

    public function issues(): HasMany
    {
        return $this->hasMany(ImportIssue::class);
    }
}
