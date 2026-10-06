<?php

namespace App\Gestionale\Questionnaire;

use App\Gestionale\CommandRejected;

/**
 * quick-request.ts quickRequestCriteria(): contract, typology, zone and budget for a draft request.
 * Never invents purpose, m², rooms, dates or priorities; a free zone becomes an honest note.
 */
final class QuickRequestCriteria
{
    public const KEYS = ['operation', 'typology', 'zone', 'budgetMin', 'budgetMax', 'notes', 'details'];

    public const DETAIL_IDS = ['purpose', 'area', 'bedrooms', 'bathrooms', 'condition', 'availableBy', 'balcony', 'terrace', 'garden',
        'garage', 'parking', 'activity', 'roadAccess', 'utilities', 'coveredParking', 'charging'];

    /**
     * quick-request.ts quickDetailQuestions()
     *
     * @param  array<string, mixed>  $criteria
     * @return list<array<string, mixed>>
     */
    public static function detailQuestions(Questionnaire $q, array $criteria): array
    {
        return array_values(array_filter($q->visible($criteria),
            fn ($x) => in_array($x['id'], self::DETAIL_IDS, true) && ($x['paths'] === 'all' || Questionnaire::hasAnswer($criteria['purpose'] ?? null))));
    }

    /** @return array<string, mixed> */
    public static function from(mixed $input, Questionnaire $q): array
    {
        $fail = fn (string $message) => throw new CommandRejected($message, 400, 'quick');

        if (! is_array($input) || ($input !== [] && array_is_list($input))) {
            $fail('Completa i dati della richiesta rapida.');
        }
        if (array_diff(array_keys($input), self::KEYS) !== []) {
            $fail('Campo della richiesta rapida non valido.');
        }
        if (! in_array($input['operation'] ?? null, ['Acquisto', 'Locazione'], true) || ! is_string($input['typology'] ?? null)
            || ! in_array($input['typology'], Questionnaire::quickTypologies(), true)) {
            $fail('Scegli contratto e tipologia.');
        }
        if (! is_string($input['zone'] ?? null) || trim($input['zone']) === '' || mb_strlen(trim($input['zone'])) > 200) {
            $fail('Indica una zona o un Comune entro 200 caratteri.');
        }
        $min = $input['budgetMin'] ?? null;
        $max = $input['budgetMax'] ?? null;
        if ($min === null && $max === null) {
            $fail('Indica almeno un limite del budget.');
        }
        $valid = fn ($n) => $n === null || ((is_int($n) || is_float($n)) && is_finite((float) $n) && $n >= 0);
        if (! $valid($min) || ! $valid($max) || ($max !== null && $max == 0) || ($min !== null && $min == 0 && $max === null)) {
            $fail('Indica un budget valido maggiore di zero.');
        }
        if ($min !== null && $max !== null && $min > $max) {
            $fail('Il budget Da non può superare il budget A.');
        }
        if (! $q->isActive('operation') || ! $q->isActive('typology') || ! $q->isActive('budget')) {
            $fail('La configurazione richiede il profilo completo. Apri la profilazione.');
        }

        $zone = trim($input['zone']);
        $configured = null;
        if ($q->isActive('zones')) {
            foreach ($q->question('zones')['options'] ?? [] as $option) {
                if (mb_strtolower(trim($option)) === mb_strtolower($zone)) {
                    $configured = $option;
                    break;
                }
            }
        }
        if (! $configured && ! $q->isActive('notes')) {
            $fail('Scegli una zona disponibile: le note libere non sono abilitate.');
        }
        if (array_key_exists('notes', $input) && $input['notes'] !== null && ! is_string($input['notes'])) {
            $fail('Le note devono essere un testo.');
        }
        $notes = implode("\n\n", array_filter([
            is_string($input['notes'] ?? null) ? trim($input['notes']) : '',
            $configured ? '' : 'Zona richiesta: '.$zone.' (da precisare nella profilazione).',
        ], fn ($p) => $p !== ''));
        if (mb_strlen($notes) > 4000) {
            $fail('Riduci le note: massimo 4.000 caratteri, compresa la zona annotata.');
        }
        if ($notes !== '' && ! $q->isActive('notes')) {
            $fail('Le note libere non sono abilitate.');
        }
        $details = $input['details'] ?? [];
        if (! is_array($details) || ($details !== [] && array_is_list($details))) {
            $fail('Dettagli della richiesta non validi.');
        }
        if (array_diff(array_keys($details), self::DETAIL_IDS) !== []) {
            $fail('Dettaglio della richiesta non valido.');
        }

        $criteria = ['operation' => $input['operation'], 'typology' => [$input['typology']],
            'budget' => array_filter(['min' => $min, 'max' => $max], fn ($v) => $v !== null)];
        if ($configured) {
            $criteria['zones'] = [$configured];
        }
        if ($notes !== '') {
            $criteria['notes'] = $notes;
        }
        foreach ($details as $key => $value) {
            if (Questionnaire::hasAnswer($value)) {
                $criteria[$key] = $value;
            }
        }

        $allowed = array_column(self::detailQuestions($q, $criteria), 'id');
        if (Questionnaire::hasAnswer($criteria['purpose'] ?? null) && ! in_array($input['typology'], Questionnaire::typologies($criteria), true)) {
            $fail('Scegli una tipologia compatibile con la finalità indicata.');
        }
        foreach ($details as $key => $value) {
            if (Questionnaire::hasAnswer($value) && ! in_array($key, $allowed, true)) {
                $fail('Scegli una finalità compatibile con i dettagli indicati.');
            }
        }

        return $criteria;
    }
}
