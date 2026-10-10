<?php

namespace App\Gestionale\Actions\Settings;

use App\Models\GestionaleSetting;

/** The settings row of an agency, created on the first save (without it the defaults of AgencySettings apply). */
final class SettingsStore
{
    public static function forUpdate(int $agencyId): GestionaleSetting
    {
        $setting = GestionaleSetting::query()->where('agency_id', $agencyId)->lockForUpdate()->first();

        return $setting ?? new GestionaleSetting(['version' => 1, 'contact_days' => (int) config('gestionale.contact_days', 7)]);
    }

    /** settings.version + 1: a first save moves the implicit version 1 to 2. */
    public static function nextVersion(GestionaleSetting $setting): int
    {
        return (int) ($setting->getOriginal('version') ?? 1) + 1;
    }
}
