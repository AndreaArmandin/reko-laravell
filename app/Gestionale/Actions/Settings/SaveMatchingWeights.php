<?php

namespace App\Gestionale\Actions\Settings;

use App\Gestionale\Audit;
use App\Gestionale\CommandRejected;
use App\Gestionale\Properties\PropertyMatcher;
use App\Models\AgencyMembership;
use App\Models\GestionaleSetting;
use Illuminate\Support\Facades\DB;

/**
 * settings.save, weights part (engine.ts): the eight weights are numbers >= 0 that sum to 100.
 * The settings version grows by one and every match is recalculated with the new weights.
 * The questions and contactDays/retentionDays already saved are left untouched.
 */
final class SaveMatchingWeights
{
    public const BUCKETS = ['zone', 'budget', 'type', 'surface', 'rooms', 'required', 'preferred', 'availability'];

    public function __construct(private readonly Audit $audit, private readonly PropertyMatcher $matcher) {}

    /** @param array<string, mixed> $weights */
    public function handle(AgencyMembership $actor, array $weights): GestionaleSetting
    {
        SettingsAccess::admin($actor);

        $clean = [];
        foreach (self::BUCKETS as $bucket) {
            $value = $weights[$bucket] ?? null;
            if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value < 0) {
                throw new CommandRejected('I pesi devono essere numeri positivi con totale 100.');
            }
            $clean[$bucket] = $value + 0;
        }
        if (array_sum($clean) != 100) {
            throw new CommandRejected('I pesi devono essere numeri positivi con totale 100.');
        }

        return DB::transaction(function () use ($actor, $clean) {
            $setting = SettingsStore::forUpdate((int) $actor->agency_id);
            $setting->matching_weights = $clean;
            $setting->version = SettingsStore::nextVersion($setting);
            $setting->save();

            $this->matcher->refreshAgency((int) $actor->agency_id);
            $this->audit->record('settings.save', $setting);

            return $setting;
        });
    }
}
