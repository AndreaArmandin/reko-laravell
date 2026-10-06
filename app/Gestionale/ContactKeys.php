<?php

namespace App\Gestionale;

use Normalizer;

/**
 * Normalized keys for duplicate warnings, ported from the gestionale (lib/crm/identity.ts,
 * census-model.ts normalizedCF). Same input, same key as the TS code, so imported data and
 * new data are compared the same way.
 */
final class ContactKeys
{
    /** nameKey: no accents, trimmed, lowercase, single spaces. */
    public static function name(?string $value): string
    {
        $value = (string) $value;
        $decomposed = Normalizer::normalize($value, Normalizer::FORM_D);
        $value = preg_replace('/\p{M}/u', '', $decomposed === false ? $value : $decomposed) ?? $value;

        return preg_replace('/\s+/u', ' ', mb_strtolower(trim($value), 'UTF-8')) ?? '';
    }

    /** emailKey: trimmed, lowercase. */
    public static function email(?string $value): string
    {
        return mb_strtolower(trim((string) $value), 'UTF-8');
    }

    /** phoneKey: digits only, no 00 prefix, Italian mobile 3xxxxxxxxx gets 39. */
    public static function phone(?string $value): string
    {
        $digits = preg_replace('/\D/', '', (string) $value) ?? '';

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (preg_match('/^3\d{9}$/', $digits) === 1) {
            $digits = '39'.$digits;
        }

        return $digits;
    }

    /** normalizedCF: no whitespace, uppercase. Empty becomes null. */
    public static function taxCode(?string $value): ?string
    {
        $value = mb_strtoupper(preg_replace('/\s+/u', '', (string) $value) ?? '', 'UTF-8');

        return $value === '' ? null : $value;
    }

    public static function channel(string $kind, ?string $value): string
    {
        return $kind === 'phone' ? self::phone($value) : self::email($value);
    }
}
