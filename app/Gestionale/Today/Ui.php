<?php

namespace App\Gestionale\Today;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;

/**
 * Small presentation helpers shared by Oggi and Obiettivi (common.tsx Pill, display-format.ts dateLabel,
 * presentation.ts statusTone).
 */
final class Ui
{
    /** presentation.ts statusTone */
    public static function tone(string $text, string $tone = ''): string
    {
        return match (true) {
            (bool) preg_match('/scadut|non rispettat|non soddisfatt|non compatibil|rifiutat|non coincide|urgente/iu', $text) || $tone === 'danger' => 'danger',
            (bool) preg_match('/sospes|annullat|non attiv|archiviat|non interessat/iu', $text) => 'neutral',
            (bool) preg_match('/da svolgere|da completar|da verificar|da contattar|condizionat|in trattativa|alta|media/iu', $text) || $tone === 'warm' => 'warm',
            (bool) preg_match('/completat|conclus|profilat|raggiunto|corrisponde|attiv|disponibile/iu', $text) || $tone === 'green' => 'green',
            (bool) preg_match('/nuov|inform|propost|attesa|programmat/iu', $text) => 'info',
            default => 'neutral',
        };
    }

    /** common.tsx Pill */
    public static function pill(string $text, string $tone = ''): HtmlString
    {
        $semantic = self::tone($text, $tone);
        $icon = ['green' => 'circle-check', 'danger' => 'triangle-alert', 'warm' => 'clock', 'info' => 'info'][$semantic] ?? 'circle';

        return new HtmlString('<span class="crm-pill '.$semantic.'">'.self::icon($icon, 14).'<span>'.e($text).'</span></span>');
    }

    public static function icon(string $name, int $size = 16): HtmlString
    {
        return new HtmlString(Blade::render('<x-gestionale.lucide :name="$name" :size="$size" />', ['name' => $name, 'size' => $size]));
    }

    /** display-format.ts dateLabel: "7 ott 2026" (date only) or "7 ott 2026, 14:30". */
    public static function dateLabel(CarbonInterface|string|null $value, bool $time = false): string
    {
        if ($value === null || $value === '') {
            return 'Da definire';
        }
        try {
            $dateOnly = is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value);
            $date = $value instanceof CarbonInterface ? $value : \Carbon\CarbonImmutable::parse($value);
            if (! $dateOnly) {
                $date = $date->setTimezone(\App\Gestionale\Goals\GoalCatalog::TIMEZONE);
            }

            return $date->locale('it')->translatedFormat('j M Y').(! $dateOnly && $time ? ', '.$date->format('H:i') : '');
        } catch (\Throwable) {
            return 'Da definire';
        }
    }

    /** toLocaleString('it-IT'): up to 3 decimals, thousands separator from 10.000. */
    public static function number(float|int $n): string
    {
        $text = number_format(abs((float) $n), 3, ',', abs($n) >= 10000 ? '.' : '');
        $text = rtrim(rtrim($text, '0'), ',');

        return ($n < 0 ? '-' : '').$text;
    }
}
