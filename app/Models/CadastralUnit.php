<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Stable unità immobiliare identity (foglio, particella, subalterno).
 */
class CadastralUnit extends Model
{
    protected $table = 'cadastral_units';

    protected $guarded = ['id'];

    /**
     * @return BelongsTo<Parcel, $this>
     */
    public function parcel(): BelongsTo
    {
        return $this->belongsTo(Parcel::class);
    }

    /**
     * @return HasMany<CadastralUnitVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(CadastralUnitVersion::class);
    }
}
