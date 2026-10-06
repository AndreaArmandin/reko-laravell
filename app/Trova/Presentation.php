<?php

namespace App\Trova;

/**
 * Text shown on Trova cards, as in lib/cadastral-result-facts.ts, lib/presentation-normalization.ts
 * and lib/trova-result-presentation.ts. Presentation only: stored data never changes.
 */
final class Presentation
{
    private const STREET_TYPES = 'VIA|VIALE|VICOLO|PIAZZA|PIAZZALE|CORSO|LARGO|STRADA|LOCALIT[AÀ]|FRAZIONE|CONTRADA|VICO|CIRCONVALLAZIONE|LUNGOMARE|LUNGARNO';

    /** Cadastral address without its floor suffix and without "SNC". */
    public static function address(string $address): string
    {
        $shown = (string) preg_replace('/^\s*\d+\s*;\s*(?=(?:'.self::STREET_TYPES.')\b)/iu', '', $address);
        $shown = (string) preg_replace('/\s+/u', ' ', trim($shown));
        $shown = (string) preg_replace('/\s*,?\s+N[.°]?\s*(?=\d)/iu', ' n. ', $shown);
        $shown = (string) preg_replace('/,\s*(?=\d)/u', ' n. ', $shown);
        $shown = trim((string) preg_replace('/\s+Piano\s+(?=(?:T|S\d*|\d+|R|U|TERRA|INTERRATO)\b).*$/iu', '', $shown));

        return (string) preg_replace('/\s*[,;]?\s*(?:n[.°]?\s*)?S[.\/]?N[.\/]?C[.]?\b[.]?/iu', ', senza numero civico', $shown);
    }

    /** Card title: the address, with sheet and parcel only when the civic number is missing. */
    public static function title(string $address, string $section, string $sheet, string $parcel): string
    {
        $shown = self::address($address);
        if ($shown === '') {
            $shown = 'Indirizzo non disponibile';
        }
        $street = (string) preg_replace('/\s+(?:SCALA|INTERNO|INT\.|PALAZZINA)\b.*$/iu', '', $shown);
        $civic = preg_match('/\sn\.\s*(\S+)/iu', $street, $m) ? strtoupper($m[1]) : null;

        return ($civic === null || $civic === 'SNC') && $sheet !== '' && $parcel !== ''
            ? $shown.' · '.($section !== '' ? 'Sez. '.$section.' · ' : '').'F. '.$sheet.' P. '.$parcel
            : $shown;
    }

    /** 4.5 → "4,5"; 5.0 → "5" */
    public static function number(float|int|string $value): string
    {
        $text = rtrim(rtrim(number_format((float) $value, 2, ',', '.'), '0'), ',');

        return $text === '' ? '0' : $text;
    }

    /** "1 vano", "5 vani", "20 m²" */
    public static function measure(float|int|string|null $value, ?string $unit): string
    {
        if ($value === null || $unit === null || $unit === '') {
            return 'Non indicata';
        }

        return self::number($value).' '.((float) $value === 1.0 && $unit === 'vani' ? 'vano' : $unit);
    }

    /** "4,5–5 vani" */
    public static function range(mixed $min, mixed $max, ?string $unit): string
    {
        if ($min === null || $unit === null) {
            return 'Non disponibile';
        }
        $text = self::number($min).((float) $max !== (float) $min && $max !== null ? '–'.self::number($max) : '');

        return $text.' '.((float) $min === 1.0 && ($max === null || (float) $max === 1.0) && $unit === 'vani' ? 'vano' : $unit);
    }

    /** Engine messages use Trova's internal path names: show the user's words (lib/trova-display.ts). */
    public static function message(string $message): string
    {
        return str_replace(['Private', 'Business'], ['Residenziale', 'Attività'], $message);
    }

    /** Trova lib/required-vani.ts dimensionRangeError */
    public static function rangeError(string $min, string $max, string $unit): string
    {
        if (trim($min) === '' || trim($max) === '') {
            return "Indica entrambi i valori in {$unit}: Da e A.";
        }
        if (! is_numeric($min) || ! is_numeric($max) || (float) $min <= 0 || (float) $max <= 0) {
            return "Inserisci valori maggiori di zero in {$unit}.";
        }

        return (float) $min > (float) $max ? 'Il valore Da non può superare il valore A.' : '';
    }
}
