<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Goal extends Model
{
    use BelongsToAgency;

    protected $table = 'goals';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['read_only' => 'boolean'];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(GoalVersion::class)->orderByDesc('version');
    }
}
