<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Versioned profiling question set (gestionale settings.questions). agency_id null = platform set.
 * Not tenant-scoped on purpose: the platform set is shared; current() always filters by agency.
 */
class QuestionSetVersion extends Model
{
    public const CLIENT_PROFILE = 'client_profile';

    protected $table = 'question_set_versions';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'questions' => 'array',
            'published_at' => 'datetime',
            'version' => 'integer',
            'profile_version' => 'integer',
        ];
    }

    /** Highest published version of the agency, else the platform one. */
    public static function current(?int $agencyId, string $code = self::CLIENT_PROFILE): ?self
    {
        $published = fn () => static::query()->where('code', $code)->whereNotNull('published_at')->orderByDesc('version');

        return ($agencyId ? $published()->where('agency_id', $agencyId)->first() : null)
            ?? $published()->whereNull('agency_id')->first();
    }

    /**
     * @return BelongsTo<Agency, $this>
     */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }
}
