<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Agency activity such as a call or visit.
 */
class Activity extends Model
{
    use BelongsToAgency;

    protected $table = 'activities';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'completed_at' => 'datetime',
            'outcome_confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'metadata' => 'array',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Agency, $this>
     */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function propertyRequest(): BelongsTo
    {
        return $this->belongsTo(PropertyRequest::class);
    }

    /**
     * @return HasMany<ActivityParticipant, $this>
     */
    public function participants(): HasMany
    {
        return $this->hasMany(ActivityParticipant::class);
    }

    /**
     * @return HasMany<ActivityUnit, $this>
     */
    public function units(): HasMany
    {
        return $this->hasMany(ActivityUnit::class);
    }

    /**
     * @return HasMany<ActivityEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(ActivityEvent::class);
    }
}
