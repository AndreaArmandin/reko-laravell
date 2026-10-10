<?php

namespace App\Gestionale;

use App\Models\GestionaleSetting;

/**
 * Settings of the current agency (gestionale settings: weights, version, contactDays, retentionDays).
 * One place to read them, so screens never fall back on the env value when the agency saved its own.
 * Without a saved row the defaults of defaultSettings() (questions.ts) apply.
 */
final class AgencySettings
{
    public const WEIGHTS = ['zone' => 20, 'budget' => 20, 'type' => 15, 'surface' => 10, 'rooms' => 10, 'required' => 15, 'preferred' => 5, 'availability' => 5];

    /** weight-presets.ts: drafts only, choosing one never saves it. */
    public const PRESETS = [
        'Equilibrato' => ['zone' => 20, 'budget' => 20, 'type' => 15, 'surface' => 10, 'rooms' => 10, 'required' => 15, 'preferred' => 5, 'availability' => 5],
        'Budget prima' => ['zone' => 15, 'budget' => 35, 'type' => 10, 'surface' => 10, 'rooms' => 5, 'required' => 15, 'preferred' => 5, 'availability' => 5],
        'Zona prima' => ['zone' => 35, 'budget' => 15, 'type' => 10, 'surface' => 10, 'rooms' => 5, 'required' => 15, 'preferred' => 5, 'availability' => 5],
    ];

    public const DEFAULT_CONTACT_DAYS = 7;

    public const DEFAULT_RETENTION_DAYS = 180;

    /** @return array{weights: array<string, int>, version: int, contactDays: int, retentionDays: int} */
    public static function for(?int $agencyId = null): array
    {
        $agencyId ??= app(CurrentAgency::class)->id();
        $row = $agencyId === null ? null : GestionaleSetting::query()->where('agency_id', $agencyId)->first();

        return [
            'weights' => array_replace(self::WEIGHTS, $row?->matching_weights ?? []),
            'version' => (int) ($row?->version ?? 1),
            'contactDays' => (int) ($row?->contact_days ?? config('gestionale.contact_days', self::DEFAULT_CONTACT_DAYS)),
            'retentionDays' => (int) ($row?->retention_days ?? self::DEFAULT_RETENTION_DAYS),
        ];
    }

    /** Days without contact after which a client needs a callback (client-card.ts clientNeedsCallback). */
    public static function contactDays(?int $agencyId = null): int
    {
        return max(1, self::for($agencyId)['contactDays']);
    }

    public static function retentionDays(?int $agencyId = null): int
    {
        return max(1, self::for($agencyId)['retentionDays']);
    }
}
