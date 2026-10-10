<?php

namespace App\Gestionale\Activities;

use App\Models\Activity;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Display rules of the agenda: display-format.ts dateLabel(), presentation.ts statusTone(),
 * the datetime-local format, and the names of the people shown on the rows.
 */
final class ActivityPresentation
{
    private const MONTHS = ['gen', 'feb', 'mar', 'apr', 'mag', 'giu', 'lug', 'ago', 'set', 'ott', 'nov', 'dic'];

    /** dateLabel(): "7 ott 2026" or "7 ott 2026, 14:30" (it-IT). */
    public static function dateLabel(CarbonInterface|string|null $value, bool $time = false): string
    {
        if ($value === null || $value === '') {
            return 'Da definire';
        }
        try {
            $date = $value instanceof CarbonInterface ? $value : Carbon::parse($value);
        } catch (\Throwable) {
            return 'Da definire';
        }
        $dateOnly = is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
        $label = $date->day.' '.self::MONTHS[$date->month - 1].' '.$date->year;

        return $time && ! $dateOnly ? $label.', '.$date->format('H:i') : $label;
    }

    /** presentation.ts statusTone() */
    public static function statusTone(string $text, string $tone = ''): string
    {
        return match (true) {
            $tone === 'danger' || preg_match('/scadut|non rispettat|non soddisfatt|non compatibil|rifiutat|non coincide|urgente/i', $text) === 1 => 'danger',
            preg_match('/sospes|annullat|non attiv|archiviat|non interessat/i', $text) === 1 => 'neutral',
            $tone === 'warm' || preg_match('/da svolgere|da completar|da verificar|da contattar|condizionat|in trattativa|alta|media/i', $text) === 1 => 'warm',
            $tone === 'green' || preg_match('/completat|conclus|profilat|raggiunto|corrisponde|attiv|disponibile/i', $text) === 1 => 'green',
            preg_match('/nuov|inform|propost|attesa|programmat/i', $text) === 1 => 'info',
            default => 'neutral',
        };
    }

    /** <input type="datetime-local"> value */
    public static function localInput(?CarbonInterface $value): string
    {
        return $value ? $value->format('Y-m-d\TH:i') : '';
    }

    /**
     * Names of the users shown on a set of activities (assignee, creator, who answered).
     *
     * @param  iterable<Activity>  $activities
     * @return Collection<int, string>
     */
    public static function userNames(iterable $activities): Collection
    {
        $ids = [];
        foreach ($activities as $a) {
            $ids[] = $a->assigned_to_user_id;
            $ids[] = $a->created_by_user_id;
            foreach ($a->events ?? [] as $event) {
                $ids[] = $event->user_id;
            }
        }
        $ids = array_values(array_unique(array_filter($ids)));

        return $ids === [] ? new Collection : User::query()->whereIn('id', $ids)->pluck('name', 'id');
    }
}
