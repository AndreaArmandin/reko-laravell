<?php

namespace App\Gestionale\Properties;

/** property-status.ts: gli stati disponibili dipendono dal contratto. */
final class PropertyStatus
{
    /** @return list<string> */
    public static function options(?string $operation): array
    {
        return ['Attivo', 'Non attivo', 'Scaduto', 'In trattativa',
            ...($operation === 'Locazione' ? ['Locato'] : ($operation === 'Acquisto' ? ['Venduto'] : []))];
    }

    /** Solo un immobile attivo o in trattativa può essere proposto o visitato. */
    public static function canPropose(string $status): bool
    {
        return in_array($status, ['Attivo', 'In trattativa'], true);
    }
}
