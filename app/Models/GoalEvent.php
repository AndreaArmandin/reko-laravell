<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;

class GoalEvent extends Model
{
    use BelongsToAgency;

    protected $table = 'goal_events';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['event_types' => 'array', 'occurred_at' => 'datetime', 'answered' => 'boolean', 'completed' => 'boolean', 'cancelled' => 'boolean'];
    }
}
