<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Proposed census change recorded by an agency.
 */
class CensusProposal extends Model
{
    use BelongsToAgency;

    protected $table = 'census_proposals';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'review' => 'array',
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
     * @return BelongsTo<CadastralUnit, $this>
     */
    public function cadastralUnit(): BelongsTo
    {
        return $this->belongsTo(CadastralUnit::class);
    }

    /**
     * @return BelongsTo<Parcel, $this>
     */
    public function parcel(): BelongsTo
    {
        return $this->belongsTo(Parcel::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function proposedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by_user_id');
    }
}
