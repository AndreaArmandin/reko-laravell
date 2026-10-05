<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Request a Trova user sends to an agency about selected parcels or units.
 */
class AgencyRequest extends Model
{
    protected $table = 'agency_requests';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reference' => 'array',
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
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    /**
     * @return HasMany<AgencyRequestAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(AgencyRequestAttachment::class);
    }
}
