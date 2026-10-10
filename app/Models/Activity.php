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

    /** updated_at is the optimistic revision of the activity commands: keep microseconds. */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'internal' => 'boolean',
            'answered' => 'boolean',
            'amount' => 'float',
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

    /**
     * Owner linked to the activity (ownerId): a contact of the agency.
     *
     * @return BelongsTo<Contact, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'owner_contact_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return BelongsTo<Parcel, $this>
     */
    public function parcel(): BelongsTo
    {
        return $this->belongsTo(Parcel::class);
    }

    /**
     * @return BelongsTo<Activity, $this>
     */
    public function previousActivity(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_activity_id');
    }

    /**
     * @return BelongsTo<Activity, $this>
     */
    public function nextActivity(): BelongsTo
    {
        return $this->belongsTo(self::class, 'next_activity_id');
    }

    /** types.ts status: Completata (done) */
    public function isDone(): bool
    {
        return $this->status === 'Completata';
    }
}
