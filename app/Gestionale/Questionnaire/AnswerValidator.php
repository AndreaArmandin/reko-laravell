<?php

namespace App\Gestionale\Questionnaire;

use App\Gestionale\CommandRejected;

/**
 * engine.ts validateAnswer() (+ tags), with the exact messages. Returns the value to store.
 */
final class AnswerValidator
{
    /** @param array<string, mixed> $question */
    public static function validate(mixed $value, array $question): mixed
    {
        $fail = fn (string $message) => throw new CommandRejected($message, 400, 'value');

        if (($question['id'] === 'operation' || ! $question['skippable']) && ! Questionnaire::hasAnswer($value)) {
            $fail($question['id'] === 'operation' ? 'Scegli Acquisto o Locazione: questa risposta è obbligatoria.' : 'Completa questa risposta obbligatoria.');
        }
        if ($value === null || $value === '') {
            return $value;
        }
        if ($question['id'] === 'activity') {
            (is_string($value) && mb_strlen($value) <= 4000) || $fail('Descrivi l’attività in un testo entro 4.000 caratteri.');

            return $value;
        }

        $number = fn ($n) => (is_int($n) || is_float($n)) && is_finite((float) $n);
        $object = fn ($v, array $keys) => is_array($v) && ($v === [] || ! array_is_list($v)) && array_diff(array_keys($v), $keys) === [];
        $options = $question['options'] ?? null;

        match ($question['type']) {
            'boolean' => is_bool($value) || $fail('Scegli sì, no oppure salta la domanda.'),
            'amount' => ($number($value) && $value >= 0) || $fail('Inserisci un numero valido, maggiore o uguale a zero.'),
            'range' => ($object($value, ['min', 'max'])
                && array_filter($value, fn ($n) => $n !== null && (! $number($n) || $n < 0)) === []
                && (! isset($value['min'], $value['max']) || $value['min'] <= $value['max']))
                || $fail('Controlla l’intervallo: il minimo non può superare il massimo.'),
            'financing' => ($object($value, ['amount', 'percent'])
                && array_filter($value, fn ($n) => $n !== null && (! $number($n) || $n < 0)) === []
                && (! isset($value['percent']) || $value['percent'] <= 100))
                || $fail('Indica un importo valido o una percentuale tra 0 e 100.'),
            'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', is_scalar($value) ? (string) $value : '') || $fail('Inserisci una data valida.'),
            'multi' => (is_array($value) && array_is_list($value)
                && array_filter($value, fn ($v) => ! is_string($v) || (is_array($options) && ! in_array($v, $options, true))) === [])
                || $fail('Seleziona le opzioni disponibili.'),
            'single' => (is_string($value) && (! is_array($options) || in_array($value, $options, true))) || $fail('Seleziona una risposta disponibile.'),
            'text', 'priority' => (is_string($value) && mb_strlen($value) <= 4000) || $fail('Inserisci un testo valido, entro 4.000 caratteri.'),
            'zone' => (is_array($value) && $number($value['lat'] ?? null) && $number($value['lng'] ?? null) && $number($value['radius'] ?? null)
                && $value['radius'] > 0 && abs($value['lat']) <= 90 && abs($value['lng']) <= 180)
                || $fail('Scegli un punto e una distanza validi.'),
            default => true,
        };

        return $question['id'] === 'tags' ? Tags::validate($value) : $value;
    }
}
