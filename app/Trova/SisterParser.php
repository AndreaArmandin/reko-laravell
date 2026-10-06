<?php

namespace App\Trova;

/**
 * Reads the text export of SISTER (fabbricati), ported from Trova lib/crm/catalog-consolidation.ts and sister-text.ts.
 *
 * Columns, tab separated or in a Markdown table:
 *   0 foglio (or sezione/foglio) · 1 particella · 2 subalterno · 3 indirizzo · 4 zona censuaria
 *   5 categoria · 6 classe · 7 consistenza ("3,5 vani", "20 m²") · 8 rendita · 9 partita · 10 flag
 * Empty cells are kept: removing them would shift category and consistency.
 */
final class SisterParser
{
    public const KIND = 'Fabbricati';

    /** Headers, totals and notes around the data: skipped, never counted as errors. */
    private const NOT_DATA = '/^(SISTER|Comune:|Foglio:|Foglio\s*\||\|?\s*[-:]+\s*\||#|Totale|Esportazione|Fonte|Data:|Sezione\b|Foglio\b)/iu';

    /** @return list<string> */
    public static function cells(string $line): array
    {
        $text = (string) preg_replace('/^\x{FEFF}/u', '', $line);
        $text = str_replace(["\u{00a0}", '&#x20;', '&#32;', '&nbsp;'], ' ', $text);

        if (str_contains($text, '|')) {
            $cells = array_map('trim', explode('|', trim($text)));
            if ($cells[0] === '') {
                array_shift($cells);
            }
            if ($cells !== [] && end($cells) === '') {
                array_pop($cells);
            }

            return $cells;
        }

        $cells = array_map('trim', explode("\t", $text));
        if ($cells[0] === '') {
            array_shift($cells);
        }

        return $cells;
    }

    /** True for blank lines and for headers, totals and notes. */
    public static function isNotData(string $line): bool
    {
        $line = trim((string) preg_replace('/^\x{FEFF}/u', '', $line));

        return $line === '' || (bool) preg_match(self::NOT_DATA, $line);
    }

    /** Null when the line is not a unit row at all. */
    public static function parse(string $line): ?SisterRecord
    {
        $c = self::cells($line);

        // Observed artefact in the last flag column of some exports, not a cadastral field
        if (count($c) === 11 && preg_match('/^\d+$/', $c[2]) && preg_match('/^(?:Fit-Active)+$/i', $c[10])) {
            $c[10] = '';
        }

        $first = $c[0] ?? '';
        if (! preg_match('/^\d+$/', $first) && ! preg_match('/^[A-Z]+\/\d+$/i', $first)) {
            return null;
        }
        $parts = explode('/', $first);
        $section = count($parts) === 2 ? strtoupper($parts[0]) : '';
        $sheet = CatalogSearch::cadastralId(end($parts));
        $parcel = CatalogSearch::cadastralId($c[1] ?? '');
        $sub = CatalogSearch::cadastralId($c[2] ?? '');
        if (! preg_match('/^[A-Z0-9]+$/', $parcel)) {
            return null;
        }

        [$value, $unit] = self::consistency($c[7] ?? '');
        $category = Categories::normalize($c[5] ?? '');
        $address = trim($c[3] ?? '');

        // Sister exports can end after category/class: those fixed columns still identify a categorised row
        $shortWithCategory = count($c) >= 6 && $category !== '';
        $malformed = (count($c) < 8 && ! $shortWithCategory) || count($c) > 11
            || (($c[10] ?? '') !== '' && ! preg_match('/^(SI|SÌ|NO)$/iu', $c[10]));

        return new SisterRecord(
            section: $section,
            sheet: $sheet,
            parcel: $parcel,
            sub: $sub,
            address: $address,
            zone: trim($c[4] ?? ''),
            category: $category,
            class: trim($c[6] ?? ''),
            value: $value,
            unit: $unit,
            rendita: self::number($c[8] ?? ''),
            partita: trim($c[9] ?? ''),
            suppressed: (bool) preg_match('/\bsoppress[oa]\b/iu', $line),
            common: (bool) preg_match('/bene\s+comune\s+non\s+censibile|\bBCNC\b/iu', $line),
            malformed: $malformed,
            identityDetail: $sub === '' ? json_encode(array_map(self::textKey(...), array_slice($c, 3)), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : '',
        );
    }

    /** @return array{0: float|null, 1: string|null} value and unit ("vani", "m²", "m³") */
    private static function consistency(string $text): array
    {
        if (! preg_match('/^\s*(\d+(?:\.\d{3})*(?:,\d+)?|\d+(?:\.\d+)?)\s*(vani?|m[²2³3]|mq|mc)\s*$/iu', $text, $m)) {
            return [null, null];
        }
        $value = self::decimal($m[1]);
        if ($value === null || $value <= 0) {
            return [null, null];
        }
        $unit = match (true) {
            (bool) preg_match('/^van/i', $m[2]) => 'vani',
            (bool) preg_match('/^(m3|m³|mc)$/iu', $m[2]) => 'm³',
            default => 'm²',
        };

        return [$value, $unit];
    }

    /** First number in a text such as "R.Euro:1563,57". */
    private static function number(string $text): ?float
    {
        return preg_match('/(\d+(?:\.\d{3})*(?:,\d+)?|\d+(?:\.\d+)?)/', $text, $m) ? self::decimal($m[1]) : null;
    }

    /** Italian "1.234,5" and plain "1234.5". */
    private static function decimal(string $text): ?float
    {
        $italian = str_contains($text, ',') || preg_match('/^\d+(?:\.\d{3})+$/', $text);
        $number = $italian ? str_replace(',', '.', str_replace('.', '', $text)) : $text;

        return is_numeric($number) ? (float) $number : null;
    }

    private static function textKey(string $text): string
    {
        if (class_exists(\Normalizer::class)) {
            $text = \Normalizer::normalize($text, \Normalizer::FORM_KC) ?: $text;
        }

        return trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/\s*([-;])\s*/u', '$1', mb_strtoupper($text))));
    }
}
