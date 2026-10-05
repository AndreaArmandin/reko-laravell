<?php

namespace App\Trova;

use Normalizer;

/**
 * Street phrase matching ported from Trova (lib/street-search.ts).
 * Searching ROMA must not select ROMAGNA; punctuation and spacing are not different streets.
 */
final class Street
{
    private const ACCENTS = ['à' => 'A', 'è' => 'E', 'é' => 'E', 'ì' => 'I', 'ò' => 'O', 'ù' => 'U',
        'À' => 'A', 'È' => 'E', 'É' => 'E', 'Ì' => 'I', 'Ò' => 'O', 'Ù' => 'U'];

    private const PUNCTUATION = ["'", '’', '.', ',', ';', ':', '-', '/', "\t", "\n", "\r"];

    public static function normalize(string $value): string
    {
        $text = (string) preg_replace(
            '/^\s*\d+\s*;\s*(?=(?:VIA|VIALE|VICOLO|PIAZZA|PIAZZALE|CORSO|LARGO|STRADA|LOCALIT[AÀ]|FRAZIONE|CONTRADA|VICO|CIRCONVALLAZIONE|LUNGOMARE|LUNGARNO)\b)/iu',
            '',
            $value,
        );
        $text = Normalizer::normalize($text, Normalizer::FORM_KC) ?: $text;
        $text = strtr($text, self::ACCENTS);
        $text = str_replace(self::PUNCTUATION, ' ', $text);

        return trim((string) preg_replace('/\s+/', ' ', mb_strtoupper($text)));
    }

    /**
     * PostgreSQL expression with the same normalization, padded with spaces
     * so that a phrase can be matched on word boundaries.
     */
    public static function sql(string $column): string
    {
        return "(' ' || regexp_replace(upper(translate({$column}, 'àèéìòùÀÈÉÌÒÙ''’.,;:-/', 'AEEIOUAEEIOU        ')), '\\s+', ' ', 'g') || ' ')";
    }
}
