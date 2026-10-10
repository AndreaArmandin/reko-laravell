<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GestionaleSetting extends Model
{
    use BelongsToAgency;

    protected $table = 'gestionale_settings';

    protected $guarded = ['id'];

    /** settings.version: ogni salvataggio delle impostazioni la incrementa (engine.ts settings.save). */
    protected static function booted(): void
    {
        static::saving(function (GestionaleSetting $setting): void {
            if ($setting->exists && $setting->isDirty() && ! $setting->isDirty('version')) {
                $setting->version = (int) ($setting->getOriginal('version') ?? 1) + 1;
            }
        });
    }

    protected function casts(): array
    {
        return ['matching_weights' => 'array', 'version' => 'integer'];
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }
}
