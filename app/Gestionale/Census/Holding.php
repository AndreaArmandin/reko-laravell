<?php

namespace App\Gestionale\Census;

/**
 * Titolarità di un proprietario su una unità: diritto e quota (holdings.ts + extractSisterHolding).
 * Un valore è un array {right, fraction, rawText, components?}: più diritti sulla stessa unità
 * (es. nuda proprietà 1/2 e usufrutto 1/2) restano nei components e non si perdono mai.
 */
final class Holding
{
    /** extractSisterHolding(): "Proprieta' per 1/2" → diritto "Proprietà", quota "1/2". */
    public static function extract(string $value, string $fraction = ''): array
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', trim($value)));
        $share = trim($fraction) !== '' ? trim($fraction) : (preg_match('/\b\d+\s*\/\s*\d+\b/u', $text, $m) ? $m[0] : '');
        $right = trim((string) preg_replace('/\s*(?:per\s+)?\b\d+\s*\/\s*\d+\b\s*/iu', ' ', $text, 1));
        $right = (string) preg_replace('/proprieta[\'’]/iu', 'Proprietà', $right);

        return [
            'right' => $right,
            'fraction' => (string) preg_replace('/\s/u', '', $share),
            'rawText' => implode(' · ', array_filter([trim($value), trim($fraction)], fn ($v) => $v !== '')),
        ];
    }

    /** Diritto e quota già validi (validHolding di census-sister.ts). */
    public static function valid(array $holding): bool
    {
        if (! empty($holding['components']) && is_array($holding['components'])) {
            $components = self::components($holding);

            return $components !== []
                && ! self::conflictingShares($holding)
                && collect($components)->every(fn (array $component) => self::valid($component));
        }

        if (! preg_match('/^(\d+)\/(\d+)$/', (string) ($holding['fraction'] ?? ''), $f)) {
            return false;
        }
        $right = (string) preg_replace('/^\(\d+\)\s*/u', '', (string) ($holding['right'] ?? ''));

        return (bool) preg_match('/^(?:propriet|nuda propriet|usufrutt|uso\b|abitazione\b|superficie\b|diritto |enfite|livellar|oneri\b|possesso\b)/iu', $right)
            && (int) $f[1] > 0 && (int) $f[2] > 0 && (int) $f[1] <= (int) $f[2];
    }

    public static function normalized(string $value): string
    {
        $value = (string) preg_replace('/^\(\d+\)\s*/u', '', $value);

        return mb_strtoupper((string) preg_replace('/\s+/u', ' ', trim($value)), 'UTF-8');
    }

    private static function key(array $part): string
    {
        return self::normalized((string) ($part['right'] ?? '')).'|'.trim((string) ($part['fraction'] ?? ''));
    }

    /** @return list<array{right:string,fraction:string,rawText:string}> */
    public static function components(array $value): array
    {
        $original = ! empty($value['components']) ? array_values($value['components']) : [$value];
        $hasContent = array_filter($original, fn ($p) => ! empty($p['right']) || ! empty($p['fraction'])) !== [];
        $parts = $hasContent ? array_values(array_filter($original, fn ($p) => ! empty($p['right']) || ! empty($p['fraction']))) : $original;

        $unique = [];
        foreach ($parts as $part) {
            $unique[self::key($part)] = ['right' => (string) ($part['right'] ?? ''), 'fraction' => (string) ($part['fraction'] ?? ''), 'rawText' => (string) ($part['rawText'] ?? '')];
        }
        uksort($unique, fn ($a, $b) => strcmp($a, $b));

        return array_values($unique);
    }

    public static function label(array $value): string
    {
        return implode('; ', array_map(fn ($p) => implode(' ', array_filter([$p['right'], $p['fraction']], fn ($v) => $v !== '')), self::components($value)));
    }

    public static function signature(array $value): string
    {
        return json_encode(array_map(fn ($p) => self::key($p), self::components($value)), JSON_UNESCAPED_UNICODE);
    }

    public static function same(array $a, array $b): bool
    {
        return self::signature($a) === self::signature($b);
    }

    /** Due quote diverse per lo stesso diritto sulla stessa unità. */
    public static function conflictingShares(array $value): bool
    {
        $parts = self::components($value);

        return count(array_unique(array_map(fn ($p) => self::normalized($p['right']), $parts))) !== count($parts);
    }

    /** combineHoldings(): uno solo → quel componente, più di uno → diritto composto con components. */
    public static function combine(array ...$values): array
    {
        $parts = self::components(['right' => '', 'fraction' => '', 'rawText' => '', 'components' => array_merge(...array_map(fn ($v) => self::components($v), $values))]);
        if (count($parts) === 1) {
            return $parts[0];
        }

        return [
            'right' => implode('; ', array_map(fn ($p) => implode(' ', array_filter([$p['right'], $p['fraction']], fn ($v) => $v !== '')), $parts)),
            'fraction' => '',
            'rawText' => implode("\n", array_map(fn ($p) => $p['rawText'], $parts)),
            'components' => $parts,
        ];
    }

    /** L'import può completare un collegamento esistente, mai cambiare o togliere una quota in silenzio. */
    public static function isExtension(array $previous, array $next): bool
    {
        $before = array_map(fn ($p) => self::key($p), self::components($previous));
        $after = array_map(fn ($p) => self::key($p), self::components($next));

        return ! self::conflictingShares($next) && array_diff($before, $after) === [] && count($after) > count($before);
    }

    /** Quota numerica per la colonna dell'intestazione (solo con un unico componente). @return array{0:?int,1:?int} */
    public static function share(array $value): array
    {
        $parts = self::components($value);
        if (count($parts) === 1 && preg_match('/^(\d+)\/(\d+)$/', $parts[0]['fraction'], $m) && (int) $m[2] > 0) {
            return [(int) $m[1], (int) $m[2]];
        }

        return [null, null];
    }
}
