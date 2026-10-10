<?php

namespace App\Gestionale\Properties;

use App\Models\Contact;
use App\Models\Property;

/**
 * demo-markers.ts: schede di prova dichiarate con nome esplicito. Mai dedotte da dati arbitrari.
 */
final class TestRecords
{
    public const TEST_MODES = ['standard', 'test', 'all'];

    /** demo-markers.ts: nomi esplicitamente dichiarati, mai dedotti da prefissi arbitrari. */
    public const TEST_CLIENT_NAMES = [
        'Cliente Demo Verifica CRM',
        'Cliente Demo Tag Verifica',
        'TEST REKO Cliente',
        'Cliente TEST',
        'Cliente TEST · dati fittizi',
    ];

    public static function isTestClient(Contact $contact): bool
    {
        return self::isTestClientName($contact->display_name);
    }

    public static function isTestClientName(?string $name): bool
    {
        return in_array($name, self::TEST_CLIENT_NAMES, true);
    }

    public static function isTestProperty(Property $property): bool
    {
        return ($property->code === 'DEMO-6' && $property->title === 'Immobile Demo Verifica CRM')
            || ($property->code === 'TEST-001' && $property->title === 'TEST REKO - Bilocale prova');
    }

    /** test-record-filter.tsx matchesTestVisibility */
    public static function matches(string $mode, bool $isTest): bool
    {
        return $mode === 'all' || ($mode === 'test' ? $isTest : ! $isTest);
    }
}
