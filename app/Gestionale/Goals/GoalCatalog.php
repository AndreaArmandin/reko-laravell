<?php

namespace App\Gestionale\Goals;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Constants and day helpers of the objectives (lib/crm/goals.ts, initial-goals.json,
 * contact-outcomes.ts). Texts are the ones of the original gestionale.
 */
final class GoalCatalog
{
    public const TIMEZONE = 'Europe/Rome';

    public const PERIODS = ['Giornaliera', 'Settimanale', 'Mensile', 'Trimestrale', 'Annuale', 'Intervallo personalizzato'];

    public const PRIORITIES = ['Alta', 'Media', 'Bassa'];

    public const METRICS = ['contacts', 'appointments', 'events', 'amount'];

    public const DEDUPS = ['person', 'event', 'property'];

    public const MODES = ['individual', 'team'];

    public const ROLES = ['scout', 'crm', 'admin'];

    /** contact-outcomes.ts */
    public const UNANSWERED_OUTCOMES = ['Non risponde', 'Numero sbagliato/inesistente', 'Trovare sul posto'];

    /** goals.ts legacyContactOutcomes */
    public const LEGACY_CONTACT_OUTCOMES = ['Non interessato', 'Non valuta al momento', 'Informazione ricevuta', 'Potenziale venditore o locatore', 'Potenziale', 'Disponibile ad approfondire', 'Appuntamento di acquisizione fissato', 'Appuntamento di acquisizione svolto', 'Incarico acquisito'];

    /** goals.ts goalEventTypes */
    public const EVENT_TYPES = ['Contatto', 'Chiamata effettuata', 'Appuntamento di acquisizione fissato', 'Appuntamento di acquisizione svolto', 'Potenziale venditore o locatore', 'Incarico acquisito', 'Incarico di vendita acquisito', 'Incarico di locazione acquisito', 'Revisione di prezzo ottenuta', 'Immobile verificato', 'Attività completata', 'Ricontatto eseguito', 'Pratica inviata per approvazione', 'Valore economico incarico', 'Cliente profilato', 'Richiesta completata', 'Immobile pubblicato', 'Immobile proposto', 'Visita fissata', 'Riscontro raccolto', 'Trattativa avviata'];

    /** Event types an activity produces by itself (syncGoalEvents `derived`): an outcome with one of these names is not added again. */
    public const DERIVED_EVENT_TYPES = ['Contatto', 'Chiamata effettuata', 'Attività completata', 'Appuntamento di acquisizione fissato', 'Appuntamento di acquisizione svolto', 'Incarico acquisito', 'Incarico di vendita acquisito', 'Incarico di locazione acquisito', 'Pratica inviata per approvazione', 'Valore economico incarico', 'Cliente profilato', 'Richiesta completata', 'Immobile pubblicato', 'Immobile proposto', 'Visita fissata', 'Trattativa avviata', 'Revisione di prezzo ottenuta', 'Riscontro raccolto'];

    /** initial-goals.json: the two objectives every agency starts with (assigned to the role scout). */
    public const INITIAL = [
        [
            'key' => 'initial-valid-contacts', 'name' => 'Contatti validi', 'description' => 'Persone con risposta effettiva, una sola volta nel periodo.',
            'category' => 'Contatti', 'metric' => 'contacts', 'target' => 20, 'unit' => 'persone', 'period' => 'Giornaliera', 'events' => ['Contatto'],
            'outcomes' => ['Non interessato', 'Non valuta al momento', 'Informazione ricevuta', 'Potenziale venditore o locatore', 'Disponibile ad approfondire', 'Appuntamento di acquisizione fissato', 'Appuntamento di acquisizione svolto', 'Incarico acquisito'],
            'dedup' => 'person', 'roles' => ['scout'], 'priority' => 'Alta', 'order' => 1, 'icon' => 'Telefono',
        ],
        [
            'key' => 'initial-acquisition-appointments', 'name' => 'Appuntamenti di acquisizione fissati', 'description' => 'Appuntamenti confermati con proprietario, immobile o interesse, giorno e ora.',
            'category' => 'Acquisizione', 'metric' => 'appointments', 'target' => 1, 'unit' => 'appuntamenti', 'period' => 'Giornaliera', 'events' => ['Appuntamento di acquisizione fissato'],
            'outcomes' => ['Appuntamento di acquisizione fissato'], 'dedup' => 'event', 'roles' => ['scout'], 'priority' => 'Alta', 'order' => 2, 'icon' => 'Calendario',
        ],
    ];

    /** contact-outcomes.ts newContactOutcomes minus the unanswered ones (answeredContactOutcomes). */
    public static function answeredContactOutcomes(): array
    {
        $all = [];
        foreach (['Vendita', 'Affitto'] as $operation) {
            foreach (['Interessato', 'Non interessato', $operation === 'Vendita' ? 'Informazione' : 'Informatore', 'Fissato appuntamento', 'Non risponde', 'Numero sbagliato/inesistente', 'Trovare sul posto', 'Preso incarico', 'Sceso prezzo'] as $outcome) {
                $all[$outcome] = true;
            }
        }

        return array_values(array_filter(array_keys($all), fn (string $o) => ! in_array($o, self::UNANSWERED_OUTCOMES, true)));
    }

    /** goals.ts validContactOutcome */
    public static function validContactOutcome(?string $outcome, ?string $operation): bool
    {
        $outcome = (string) $outcome;

        return in_array($outcome, self::LEGACY_CONTACT_OUTCOMES, true)
            || ($operation !== null && $operation !== '' && in_array($outcome, self::answeredContactOutcomes(), true));
    }

    /** goals.ts goalDay: the day (Y-m-d) of an instant in Europe/Rome. */
    public static function day(DateTimeInterface|string $value): string
    {
        return CarbonImmutable::parse($value)->setTimezone(self::TIMEZONE)->toDateString();
    }

    public static function today(DateTimeInterface|string|null $now = null): string
    {
        return self::day($now ?? now());
    }

    /** goals.ts validDay */
    public static function validDay(mixed $value): bool
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }
        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value)->toDateString() === $value;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function shift(string $day, int $days): string
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $day)->addDays($days)->toDateString();
    }

    /** presentation.ts goalUnit */
    public static function unit(float|int $value, string $unit): string
    {
        $forms = [
            'appuntamento' => ['appuntamento', 'appuntamenti'], 'appuntamenti' => ['appuntamento', 'appuntamenti'],
            'contatto' => ['contatto', 'contatti'], 'contatti' => ['contatto', 'contatti'],
            'visita' => ['visita', 'visite'], 'visite' => ['visita', 'visite'],
            'telefonata' => ['telefonata', 'telefonate'], 'telefonate' => ['telefonata', 'telefonate'],
            'evento' => ['evento', 'eventi'], 'eventi' => ['evento', 'eventi'],
            'immobile' => ['immobile', 'immobili'], 'immobili' => ['immobile', 'immobili'],
        ];

        return $forms[mb_strtolower($unit)][$value == 1 ? 0 : 1] ?? $unit;
    }
}
