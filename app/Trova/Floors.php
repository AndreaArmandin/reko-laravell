<?php

namespace App\Trova;

/**
 * Floors written at the end of a cadastral address, as in Trova lib/crm/housing-context.ts.
 * Separators enumerate levels: "10 - 5" means 10 and 5, never 5…10.
 * Unsupported abbreviations stay unknown; no physical layout is invented.
 */
final class Floors
{
    /**
     * @return list<int>|null
     */
    public static function residential(string $address): ?array
    {
        $parts = explode(' PIANO ', mb_strtoupper($address));
        $raw = trim($parts[1] ?? '');
        if ($raw === '' || mb_strlen($raw) > 400) {
            return null;
        }
        $raw = str_replace([' ', "\u{00a0}"], '', (string) preg_replace('/[–—−;,\/\t]/u', '-', $raw));
        $tokens = explode('-', $raw);
        if (count($tokens) > 64 || in_array('', $tokens, true)) {
            return null;
        }

        $levels = [];
        foreach ($tokens as $p) {
            if ($p === 'T' || $p === 'PT') {
                $levels[0] = 0;
            } elseif (preg_match('/^S([1-9])$/', $p, $m)) {
                $levels[-(int) $m[1]] = -(int) $m[1];
            } elseif (preg_match('/^\d{1,2}$/', $p) && (int) $p <= 49) {
                $levels[(int) $p] = (int) $p;
            } else {
                return null;
            }
        }
        sort($levels);

        return $levels;
    }

    /** "Piano terra", "Piano interrato S1", "Piano 3" */
    public static function label(int $floor): string
    {
        return $floor === 0 ? 'Piano terra' : ($floor < 0 ? 'Piano interrato S'.(-$floor) : 'Piano '.$floor);
    }

    /**
     * "T · 1 · S1" or "Non disponibile"
     *
     * @param  list<int>|null  $floors
     */
    public static function text(?array $floors): string
    {
        return $floors ? implode(' · ', array_map(fn (int $n) => $n === 0 ? 'T' : ($n < 0 ? 'S'.(-$n) : (string) $n), $floors)) : 'Non disponibile';
    }

    /** Box floor choice: "Terra (T)", "S1", "Piano 2" */
    public static function option(int $floor): string
    {
        return $floor === 0 ? 'Terra (T)' : ($floor < 0 ? 'S'.(-$floor) : self::label($floor));
    }
}
