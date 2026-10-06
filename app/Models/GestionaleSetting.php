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

    protected function casts(): array
    {
        return ['matching_weights' => 'array'];
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }
}
