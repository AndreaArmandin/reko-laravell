<?php

namespace App\Gestionale\Scouting;

use Illuminate\Support\Carbon;

/** Presentation helpers of the scouting screens (display-format.ts dateLabel, presentation.ts statusTone). */
final class Format
{
    /** dateLabel(value, true): "7 ott 2026, 14:30" */
    public static function dateLabel(mixed $value, bool $time = false): string
    {
        if ($value === null || $value === '') {
            return 'Da definire';
        }
        try {
            $date = Carbon::parse($value);
        } catch (\Throwable) {
            return 'Da definire';
        }
        $dateOnly = is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value);

        return $date->locale('it')->isoFormat('D MMM YYYY').(! $dateOnly && $time ? ', '.$date->format('H:i') : '');
    }

    /** presentation-normalization.ts normalizeAddress */
    public static function normalizeAddress(string $value): string
    {
        $v = (string) preg_replace('/\s+/u', ' ', trim($value));
        $v = (string) preg_replace('/^\d+\s*;\s*(?=(?:VIA|VIALE|VICOLO|PIAZZA|PIAZZALE|CORSO|LARGO|STRADA|LOCALIT[AÀ]|FRAZIONE|CONTRADA|VICO|CIRCONVALLAZIONE|LUNGOMARE|LUNGARNO)\b)/iu', '', $v);
        $v = (string) preg_replace('/\s*,?\s+N[.°]?\s*(?=\d)/iu', ' n. ', $v);

        return (string) preg_replace('/,\s*(?=\d)/u', ' n. ', $v);
    }

    /** presentation.ts statusTone */
    public static function statusTone(string $text, string $tone = ''): string
    {
        return match (true) {
            $tone === 'danger' || (bool) preg_match('/scadut|non rispettat|non soddisfatt|non compatibil|rifiutat|non coincide|urgente/i', $text) => 'danger',
            (bool) preg_match('/sospes|annullat|non attiv|archiviat|non interessat/i', $text) => 'neutral',
            $tone === 'warm' || (bool) preg_match('/da svolgere|da completar|da verificar|da contattar|condizionat|in trattativa|alta|media/i', $text) => 'warm',
            $tone === 'green' || (bool) preg_match('/completat|conclus|profilat|raggiunto|corrisponde|attiv|disponibile/i', $text) => 'green',
            (bool) preg_match('/nuov|inform|propost|attesa|programmat/i', $text) => 'info',
            default => 'neutral',
        };
    }
}
