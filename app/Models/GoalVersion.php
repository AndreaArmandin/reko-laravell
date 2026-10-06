<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoalVersion extends Model
{
    use BelongsToAgency;

    protected $table = 'goal_versions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['definition' => 'array', 'effective_from' => 'date'];
    }

    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class);
    }
}
