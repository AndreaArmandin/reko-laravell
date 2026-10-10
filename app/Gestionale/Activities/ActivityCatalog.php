<?php

namespace App\Gestionale\Activities;

use App\Models\Activity;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Constants and pure rules of the agenda (types.ts, operational-agenda.ts, activity.ts,
 * contact-outcomes.ts, goals.ts outcomes, call-result-options.ts, presentation.ts).
 * Texts are the ones of the original gestionale.
 */
final class ActivityCatalog
{
    /** types.ts activityTypes (stored kind) */
    public const TYPES = ['Telefonata', 'Sopralluogo', 'Incontro', 'Altro', 'Messaggio', 'Email', 'Appuntamento', 'Appuntamento di acquisizione', 'Visita', 'Proposta di immobile', 'Riscontro', 'Nota', 'Documento richiesto', 'Documento ricevuto', 'Trattativa', 'Prossima attività', 'Promemoria'];

    /** activity-form.tsx: types that cannot be chosen in the form */
    public const NOT_SELECTABLE = ['Promemoria', 'Prossima attività', 'Appuntamento di acquisizione'];

    public const PRIORITIES = ['Normale', 'Alta', 'Urgente'];

    public const STATUSES = ['Da svolgere', 'Accettata', 'Rinviata', 'Chiarimenti richiesti', 'Completata', 'Annullata'];

    public const RESPONSES = ['Accetta', 'Rinvia', 'Chiedi chiarimenti', 'Annulla'];

    public const CONTACT_OPERATIONS = ['Vendita', 'Affitto'];

    public const CRM_OPERATIONS = ['Acquisto', 'Affitto'];

    public const CRM_CATEGORIES = ['Fissato visita', 'Visitato immobile', 'Fissati conteggi', 'Fatto proposta', 'Proposta accettata', 'Proposta rifiutata', 'Reperire documenti atto'];

    /** operational-agenda.ts agendaSortLabels */
    public const SORT_LABELS = [
        'priority' => 'Da svolgere, in ordine di scadenza',
        'date' => 'Data: prima le più vicine',
        'dateDesc' => 'Data: prima le più lontane',
        'title' => 'Titolo A–Z',
    ];

    /** operational-agenda.ts activityGroups (presentation only) */
    public const GROUPS = [
        ['id' => 'contacts', 'label' => 'Contatti', 'types' => ['Telefonata', 'Messaggio', 'Email']],
        ['id' => 'appointments', 'label' => 'Appuntamenti e visite', 'types' => ['Appuntamento', 'Visita', 'Sopralluogo', 'Incontro', 'Appuntamento di acquisizione']],
        ['id' => 'documents', 'label' => 'Documenti', 'types' => ['Documento richiesto', 'Documento ricevuto']],
        ['id' => 'commercial', 'label' => 'Proposte e trattative', 'types' => ['Proposta di immobile', 'Riscontro', 'Trattativa']],
        ['id' => 'reminders', 'label' => 'Promemoria', 'types' => ['Promemoria', 'Prossima attività']],
        ['id' => 'other', 'label' => 'Note e altro', 'types' => ['Nota', 'Altro']],
    ];

    /** contact-outcomes.ts */
    public const UNANSWERED_OUTCOMES = ['Non risponde', 'Numero sbagliato/inesistente', 'Trovare sul posto'];

    /** goals.ts legacyContactOutcomes */
    public const LEGACY_OUTCOMES = ['Non interessato', 'Non valuta al momento', 'Informazione ricevuta', 'Potenziale venditore o locatore', 'Potenziale', 'Disponibile ad approfondire', 'Appuntamento di acquisizione fissato', 'Appuntamento di acquisizione svolto', 'Incarico acquisito'];

    /** goals.ts excludedContactOutcomes */
    public const EXCLUDED_OUTCOMES = ['Non risponde', 'Numero sbagliato/inesistente', 'Trovare sul posto', 'Non raggiunto', 'Nessuna risposta', 'Nessun contatto', 'Numero non valido', 'Numero non trovato', 'Da richiamare', 'Ricontatto concordato', 'Attività annullata', 'Attività non svolta', 'Messaggio o email senza risposta'];

    /** call-result-options.ts callResultOptions */
    public const CALL_RESULTS = [
        ['id' => 'no_answer', 'label' => 'Non risponde', 'outcome' => 'Nessuna risposta', 'answered' => false],
        ['id' => 'callback', 'label' => 'Richiama il…', 'outcome' => 'Da richiamare', 'answered' => true],
        ['id' => 'interested', 'label' => 'Interessato', 'outcome' => 'Disponibile ad approfondire', 'answered' => true],
        ['id' => 'not_interested', 'label' => 'Non interessato', 'outcome' => 'Non interessato', 'answered' => true],
    ];

    /** contact-outcomes.ts outcomesForContact() */
    public static function outcomesForContact(string $operation): array
    {
        return ['Interessato', 'Non interessato', $operation === 'Vendita' ? 'Informazione' : 'Informatore',
            'Fissato appuntamento', 'Non risponde', 'Numero sbagliato/inesistente', 'Trovare sul posto', 'Preso incarico', 'Sceso prezzo'];
    }

    /** contact-outcomes.ts newContactOutcomes / answeredContactOutcomes */
    public static function answeredContactOutcomes(): array
    {
        $all = array_values(array_unique([...self::outcomesForContact('Vendita'), ...self::outcomesForContact('Affitto')]));

        return array_values(array_diff($all, self::UNANSWERED_OUTCOMES));
    }

    /** goals.ts contactOutcomes */
    public static function contactOutcomes(): array
    {
        return array_values(array_unique([...self::answeredContactOutcomes(), ...self::LEGACY_OUTCOMES]));
    }

    /** activity-form.tsx: suggestions of the "Esito" field */
    public static function formOutcomes(?string $contactOperation = null): array
    {
        if ($contactOperation) {
            return self::outcomesForContact($contactOperation);
        }
        $all = array_values(array_unique([...self::contactOutcomes(), ...self::EXCLUDED_OUTCOMES, 'Svolta', 'Informazione registrata']));

        return array_values(array_filter($all, fn (string $value) => ! self::suspendedAcquisition(['outcome' => $value])));
    }

    /** activity.ts suspendedAcquisition(): the acquisition path is suspended, its history stays read-only. */
    public static function suspendedAcquisition(array|Activity $activity): bool
    {
        $type = is_array($activity) ? ($activity['type'] ?? $activity['kind'] ?? null) : $activity->kind;
        $outcome = trim((string) (is_array($activity) ? ($activity['outcome'] ?? '') : $activity->outcome));

        return $type === 'Appuntamento di acquisizione'
            || in_array($outcome, ['ACQ', 'Incarico acquisito', 'Appuntamento di acquisizione fissato', 'Appuntamento di acquisizione svolto'], true);
    }

    /** activity.ts isActivityOverdue(): one definition for dashboard, agenda and record cards. */
    public static function isOverdue(Activity $activity, ?CarbonInterface $now = null): bool
    {
        return ! in_array($activity->status, ['Completata', 'Annullata'], true)
            && ! self::suspendedAcquisition($activity)
            && $activity->scheduled_at !== null
            && $activity->scheduled_at->lt($now ?? Carbon::now());
    }

    /** presentation.ts activityResponseLabel() */
    public static function responseLabel(string $value): string
    {
        return ['Rinvia' => 'Sposta', 'Chiedi chiarimenti' => 'Chiedi dettagli al referente'][$value] ?? $value;
    }

    /** operational-agenda.ts matchesActivityGroup() */
    public static function matchesGroup(Activity $activity, string $group): bool
    {
        if ($group === 'Tutti') {
            return true;
        }
        foreach (self::GROUPS as $item) {
            if ($item['id'] === $group) {
                return in_array($activity->kind, $item['types'], true);
            }
        }

        return false;
    }

    /** @return list<string> activity types of a group id */
    public static function groupTypes(string $group): array
    {
        foreach (self::GROUPS as $item) {
            if ($item['id'] === $group) {
                return $item['types'];
            }
        }

        return [];
    }

    /**
     * operational-agenda.ts sortAgenda() on a collection of activities.
     *
     * @param  iterable<Activity>  $activities
     * @return list<Activity>
     */
    public static function sort(iterable $activities, string $order): array
    {
        $time = fn (Activity $a) => $a->scheduled_at?->getTimestamp() ?? 0;
        $items = [...$activities];
        usort($items, function (Activity $a, Activity $b) use ($order, $time) {
            $cmp = match ($order) {
                'title' => self::italianCompare($a->subject, $b->subject),
                'dateDesc' => $time($b) <=> $time($a),
                'date' => $time($a) <=> $time($b),
                // activity.ts agendaOrder: open first, then by due date
                default => ((int) $a->isDone() <=> (int) $b->isDone()) ?: ($time($a) <=> $time($b)),
            };

            return $cmp !== 0 ? $cmp : $a->id <=> $b->id;
        });

        return $items;
    }

    /** String.localeCompare(…, 'it'): case/accent-insensitive first, like ICU. */
    public static function italianCompare(string $a, string $b): int
    {
        $fold = fn (string $v) => mb_strtolower(strtr($v, ['à' => 'a', 'è' => 'e', 'é' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u', 'À' => 'a', 'È' => 'e', 'É' => 'e', 'Ì' => 'i', 'Ò' => 'o', 'Ù' => 'u']));

        return strcmp($fold($a), $fold($b)) ?: strcmp($a, $b);
    }

    /**
     * operational-agenda.ts overduePartition().
     *
     * @param  iterable<Activity>  $activities
     * @return array{overdue: list<Activity>, current: list<Activity>}
     */
    public static function overduePartition(iterable $activities, ?CarbonInterface $now = null): array
    {
        $overdue = [];
        $current = [];
        foreach ($activities as $activity) {
            if (self::isOverdue($activity, $now)) {
                $overdue[] = $activity;
            } else {
                $current[] = $activity;
            }
        }

        return ['overdue' => $overdue, 'current' => $current];
    }
}
