<?php

namespace App\Gestionale\Crm;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Presentation helpers of the clients, requests and matches screens (presentation.ts, display-format.ts):
 * the tone and icon of a pill, the initials and colour of an avatar, Italian dates and money.
 */
final class Presenter
{
    /** presentation.ts personInitials() */
    public static function initials(string $name): string
    {
        $letters = [];
        foreach (preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            if (preg_match('/\p{L}/u', $part, $m)) {
                $letters[] = $m[0];
            }
        }
        $value = count($letters) > 1 ? $letters[0].$letters[count($letters) - 1] : ($letters[0] ?? '?');

        return mb_strtoupper($value, 'UTF-8');
    }

    /** presentation.ts avatarTone(): 0–4, stable for an id. */
    public static function avatarTone(string|int $id): int
    {
        $n = 0;
        foreach (mb_str_split((string) $id) as $char) {
            $n = (($n * 31) + mb_ord($char)) & 0xFFFFFFFF;
        }

        return $n % 5;
    }

    /** presentation.ts statusTone(): danger, neutral, warm, green, info. */
    public static function statusTone(string $text, string $tone = ''): string
    {
        return match (true) {
            $tone === 'danger' || preg_match('/scadut|non rispettat|non soddisfatt|non compatibil|rifiutat|non coincide|urgente/iu', $text) === 1 => 'danger',
            preg_match('/sospes|annullat|non attiv|archiviat|non interessat/iu', $text) === 1 => 'neutral',
            $tone === 'warm' || preg_match('/da svolgere|da completar|da verificar|da contattar|condizionat|in trattativa|alta|media/iu', $text) === 1 => 'warm',
            $tone === 'green' || preg_match('/completat|conclus|profilat|raggiunto|corrisponde|attiv|disponibile/iu', $text) === 1 => 'green',
            preg_match('/nuov|inform|propost|attesa|programmat/iu', $text) === 1 => 'info',
            default => 'neutral',
        };
    }

    /** common.tsx Pill: the lucide icon of a tone. */
    public static function toneIcon(string $tone): string
    {
        return match ($tone) {
            'green' => 'circle-check',
            'danger' => 'triangle-alert',
            'warm' => 'clock',
            'info' => 'info',
            default => 'circle',
        };
    }

    /** display-format.ts dateLabel(): "6 ott 2026", with the time "6 ott 2026, 14:30". */
    public static function dateLabel(DateTimeInterface|string|null $value, bool $time = false): string
    {
        if ($value === null || $value === '') {
            return 'Da definire';
        }
        try {
            $dateOnly = is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
            $date = $value instanceof DateTimeInterface ? Carbon::instance($value) : Carbon::parse($value);
        } catch (\Throwable) {
            return 'Da definire';
        }
        $date = $date->locale('it');

        return $dateOnly || ! $time ? $date->translatedFormat('j M Y') : $date->translatedFormat('j M Y, H:i');
    }

    /** display-format.ts localDateTime(): value of a datetime-local input. */
    public static function localDateTime(DateTimeInterface|string|null $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        try {
            return Carbon::parse($value)->format('Y-m-d\TH:i');
        } catch (\Throwable) {
            return '';
        }
    }

    /** matches.tsx primaryMatchReason() */
    public static function primaryMatchReason(array $result): string
    {
        $comparisons = $result['comparisons'] ?? [];
        $critical = fn (array $c) => in_array($c['classification'] ?? '', ['Indispensabile', 'Da escludere'], true);
        foreach ($comparisons as $c) {
            if (($c['status'] ?? '') === 'missing' && $critical($c)) {
                return ($c['classification'] === 'Indispensabile' ? 'Requisito indispensabile non soddisfatto' : 'Elemento da escludere presente').': '.$c['label'];
            }
        }
        foreach ($comparisons as $c) {
            if (($c['status'] ?? '') === 'unknown' && $critical($c)) {
                return 'Dato decisivo da verificare: '.$c['label'];
            }
        }

        return $result['reasons'][0] ?? '';
    }
}
