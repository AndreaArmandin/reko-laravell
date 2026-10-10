<?php

namespace App\Gestionale\Properties;

use Illuminate\Database\Eloquent\Builder;

/**
 * Filtri dell'elenco immobili (properties.tsx Properties): ricerca, record di prova, schede incomplete e incarichi in scadenza.
 */
final class PropertyFilters
{
    public const TEST_MODES = TestRecords::TEST_MODES;

    /** `${title} ${address} ${civic} ${zone} ${code}` contiene il testo cercato. */
    public static function search(Builder $query, string $term): Builder
    {
        $term = trim($term);
        if ($term === '') {
            return $query;
        }
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';

        return $query->whereRaw("(properties.title || ' ' || coalesce(properties.address, '') || ' ' || coalesce(properties.civic, '') || ' ' || coalesce(properties.zone, '') || ' ' || coalesce(properties.code, '')) ilike ?", [$like]);
    }

    /** matchesTestVisibility(): lista principale senza test, solo test, oppure tutte. */
    public static function testVisibility(Builder $query, string $mode): Builder
    {
        $isTest = "((properties.code = 'DEMO-6' and properties.title = 'Immobile Demo Verifica CRM') or (properties.code = 'TEST-001' and properties.title = 'TEST REKO - Bilocale prova'))";

        return match ($mode) {
            'all' => $query,
            'test' => $query->whereRaw($isTest),
            default => $query->whereRaw("not {$isTest}"),
        };
    }

    /** Scheda incompleta: senza descrizione o superficie, o con annuncio non pubblicato o senza collegamento. */
    public static function incomplete(Builder $query): Builder
    {
        return $query->whereRaw("(coalesce(properties.description, '') = ''
            or coalesce(nullif(properties.features->>'area', ''), '0') in ('0', '0.0')
            or coalesce(properties.publication->>'status', '') <> 'Pubblicato'
            or coalesce(properties.publication->>'url', '') = '')");
    }

    /** Incarico che scade entro i giorni di promemoria (mandate.end < oggi + reminderDays), esclusi venduti e locati. */
    public static function expiring(Builder $query): Builder
    {
        return $query->whereRaw("properties.status not in ('Venduto', 'Locato') and case
            when properties.mandate->>'end' ~ '^\\d{4}-\\d{2}-\\d{2}\$' and properties.mandate->>'reminderDays' ~ '^\\d+\$'
            then timezone('UTC', (properties.mandate->>'end')::date::timestamp) < now() + ((properties.mandate->>'reminderDays')::int * interval '1 day')
            else false end");
    }
}
