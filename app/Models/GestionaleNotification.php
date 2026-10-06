<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GestionaleNotification extends Model
{
    use BelongsToAgency;

    protected $table = 'gestionale_notifications';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'read_at' => 'datetime'];
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    public function acquisition(): BelongsTo
    {
        return $this->belongsTo(Acquisition::class);
    }
}
