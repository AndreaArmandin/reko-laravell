<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;

class GoalLedger extends Model
{
    use BelongsToAgency;

    protected $table = 'goal_ledger';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'period_start' => 'date', 'period_end' => 'date', 'rule' => 'array'];
    }
}
