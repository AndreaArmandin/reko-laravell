<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Agency acquisition file.
 */
class Acquisition extends Model
{
    protected $table = 'acquisitions';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'opened_on' => 'date',
            'closed_on' => 'date',
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
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * @return HasMany<AcquisitionUnit, $this>
     */
    public function units(): HasMany
    {
        return $this->hasMany(AcquisitionUnit::class);
    }

    /**
     * @return HasMany<AcquisitionContact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(AcquisitionContact::class);
    }

    /**
     * @return HasMany<AcquisitionEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(AcquisitionEvent::class);
    }
}
