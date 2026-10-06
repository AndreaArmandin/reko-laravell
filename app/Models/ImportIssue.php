<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $severity
 * @property string $code
 * @property string $message
 * @property int|null $row_number
 * @property array<string, mixed>|null $payload
 */
class ImportIssue extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    /**
     * @return BelongsTo<ImportRun, $this>
     */
    public function importRun(): BelongsTo
    {
        return $this->belongsTo(ImportRun::class);
    }
}
