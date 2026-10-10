<?php

namespace App\Gestionale\Questionnaire;

use Illuminate\Support\Carbon;

/**
 * Presentation of the questionnaire for operators (request-labels.ts, matching.ts answerLabel,
 * display-format.ts money/dateLabel) and conversion between form fields and stored answers.
 * Presentation only: stored answers and their meaning never change here.
 */
final class AnswerPresenter
{
    /** request-labels.ts operatorLabels: wording for the operator of the built-in questions. */
    public const OPERATOR_LABELS = [
        'operation' => 'Il cliente cerca in acquisto o in affitto?',
        'purpose' => 'Per quale finalità cerca?',
        'typology' => 'Quali tipi di immobile valuta?',
        'zones' => 'In quali zone cerca?',
        'budget' => 'Qual è il budget del cliente?',
        'area' => 'Quanti metri quadrati cerca?',
        'bedrooms' => 'Quante camere da letto servono?',
        'availableBy' => 'Entro quando serve l’immobile?',
        'nearPoint' => 'La ricerca deve restare vicino a un punto preciso?',
        'fees' => 'Qual è il limite mensile per le spese?',
        'rooms' => 'Quanti locali servono?',
        'bathrooms' => 'Quanti bagni preferisce?',
        'floor' => 'Quali piani valuta?',
        'balcony' => 'Desidera un balcone?',
        'terrace' => 'Serve un terrazzo?',
        'garden' => 'Desidera un giardino?',
        'garage' => 'Serve un garage o box?',
        'parking' => 'È importante avere un posto auto?',
        'cellar' => 'Serve una cantina?',
        'furnished' => 'Preferisce un immobile arredato?',
        'condition' => 'In quale stato cerca l’immobile?',
        'works' => 'È disponibile a fare lavori?',
        'energy' => 'Quali classi energetiche preferisce?',
        'services' => 'Quali servizi desidera vicini?',
        'heating' => 'Ha preferenze sul riscaldamento?',
        'airConditioning' => 'Desidera la climatizzazione?',
        'visitTimes' => 'Quando è disponibile per le visite?',
        'excludeNotes' => 'Che cosa vuole escludere?',
        'notes' => 'Quali altre esigenze ha indicato?',
        'currentHome' => 'Qual è la situazione abitativa attuale?',
        'sellCurrentHome' => 'Per acquistare deve prima vendere casa?',
        'capital' => 'Quale capitale prevede di utilizzare?',
        'mortgage' => 'Prevede di richiedere un mutuo?',
        'mortgageState' => 'Ha già parlato con una banca o un consulente?',
        'mortgageEstimate' => 'Quale mutuo gli è stato indicato?',
        'financeable' => 'È già noto un importo indicativamente finanziabile?',
        'occupants' => 'Quante persone utilizzeranno l’abitazione?',
        'rentalDuration' => 'Per quanto tempo prevede di restare?',
        'incomeBand' => 'Fascia di reddito documentabile, se comunicata',
        'workSituation' => 'Situazione lavorativa, se utile alla pratica',
        'guarantees' => 'Quali garanzie vuole valutare?',
        'activity' => 'Quale attività intende svolgere?',
        'traffic' => 'Quale tipo di passaggio cerca?',
        'windows' => 'Quante vetrine servono almeno?',
        'streetEntrance' => 'Serve un ingresso diretto dalla strada?',
        'loading' => 'Serve accesso per carico e scarico?',
        'warehouse' => 'Serve un magazzino?',
        'flue' => 'Serve una canna fumaria?',
        'cadastralCategory' => 'Ha una categoria catastale di riferimento?',
        'investmentCapital' => 'Quale capitale destina all’investimento?',
        'investmentFinancing' => 'Prevede un finanziamento?',
        'expectedYield' => 'Quale rendimento indicativo vuole valutare?',
        'investmentStrategy' => 'Punta al reddito o alla rivalutazione?',
        'occupancy' => 'Preferisce un immobile libero o già locato?',
        'management' => 'Preferisce gestione diretta o delegata?',
        'exitStrategy' => 'Ha già una strategia di uscita?',
        'riskTolerance' => 'Quale livello indicativo di rischio vuole valutare?',
        'landUse' => 'Quale tipo di terreno cerca?',
        'roadAccess' => 'Serve un accesso carrabile?',
        'utilities' => 'Quali allacci servono?',
        'coveredParking' => 'Cerca uno spazio coperto?',
        'charging' => 'Serve la predisposizione per la ricarica elettrica?',
        'landProject' => 'Quale progetto intende realizzare sul terreno?',
    ];

    public const MONEY = ['budget', 'price', 'fees', 'capital', 'investmentCapital', 'financeable'];

    /** @param array<string, mixed> $question */
    public static function questionLabel(array $question): string
    {
        $default = collect(Questionnaire::defaultQuestions())->firstWhere('id', $question['id']);

        return ($default['text'] ?? null) === $question['text'] && isset(self::OPERATOR_LABELS[$question['id']])
            ? self::OPERATOR_LABELS[$question['id']]
            : $question['text'];
    }

    /** request-labels.ts operatorReason(): the built-in question wording of a match reason becomes the operator's wording. */
    public static function operatorReason(string $reason): string
    {
        foreach (Questionnaire::defaultQuestions() as $question) {
            if (isset(self::OPERATOR_LABELS[$question['id']])) {
                $reason = \Illuminate\Support\Str::replaceFirst($question['text'], self::OPERATOR_LABELS[$question['id']], $reason);
            }
        }

        return $reason;
    }

    /**
     * request-labels.ts comparisonAnswerLabel(): the comparison text saved with a match, formatted for reading
     * (dates, money, m²). Old comparisons stay faithful: the saved text is parsed, not recomputed.
     */
    public static function comparisonAnswerLabel(string $id, string $label): string
    {
        if (in_array($id, ['availableBy', 'availableFrom', 'dueDate'], true) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $label)) {
            return self::date($label);
        }
        $numeric = '([0-9]+(?:\.[0-9]{3})*(?:,[0-9]+)?)';
        $scalar = preg_match('/^'.$numeric.'$/', $label, $s) === 1;
        $range = preg_match('/^(?:da '.$numeric.')?(?: ?a '.$numeric.')?$/', $label, $r) === 1;
        $parse = fn (string $n) => (float) str_replace(',', '.', str_replace('.', '', $n));
        $hasRange = $range && (($r[1] ?? '') !== '' || ($r[2] ?? '') !== '');
        if (in_array($id, self::MONEY, true)) {
            if ($scalar) {
                return self::money($parse($s[1]));
            }
            if ($hasRange) {
                return self::money(array_filter(['min' => ($r[1] ?? '') !== '' ? $parse($r[1]) : null, 'max' => ($r[2] ?? '') !== '' ? $parse($r[2]) : null], fn ($v) => $v !== null));
            }
        }
        if ($id === 'area' && ($scalar || $hasRange)) {
            return $label.' m²';
        }

        return $label;
    }

    public static function number(int|float $n): string
    {
        return number_format($n, floor($n) == $n ? 0 : 2, ',', '.');
    }

    /** display-format.ts money() */
    public static function money(mixed $value): string
    {
        if (is_int($value) || is_float($value)) {
            return self::number($value).' €';
        }
        if (is_array($value)) {
            $lo = isset($value['min']) && is_numeric($value['min']);
            $hi = isset($value['max']) && is_numeric($value['max']);
            if ($lo && $hi) {
                return $value['min'] == $value['max'] ? self::number($value['min']).' €' : self::number($value['min']).'–'.self::number($value['max']).' €';
            }
            if ($lo) {
                return 'da '.self::number($value['min']).' €';
            }
            if ($hi) {
                return 'fino a '.self::number($value['max']).' €';
            }
        }

        return 'Da definire';
    }

    /** matching.ts answerLabel() */
    public static function label(mixed $value): string
    {
        if ($value === true) {
            return 'Sì';
        }
        if ($value === false) {
            return 'No';
        }
        if (! Questionnaire::hasAnswer($value)) {
            return 'Da definire';
        }
        if (is_array($value) && array_is_list($value)) {
            return implode(', ', $value);
        }
        if (is_array($value)) {
            if (array_key_exists('label', $value) || array_key_exists('lat', $value)) {
                return ($value['label'] ?? 'Punto scelto').' · entro '.($value['radius'] ?? '?').' km';
            }
            if (array_key_exists('amount', $value) || array_key_exists('percent', $value)) {
                return implode(' · ', array_filter([
                    isset($value['amount']) ? self::number($value['amount']).' €' : '',
                    isset($value['percent']) ? self::number($value['percent']).'%' : '',
                ]));
            }

            return implode(' ', array_filter([
                isset($value['min']) ? 'da '.self::number($value['min']) : '',
                isset($value['max']) ? 'a '.self::number($value['max']) : '',
            ]));
        }

        return is_int($value) || is_float($value) ? self::number($value) : (string) $value;
    }

    /** request-labels.ts requestAnswerLabel() */
    public static function answerLabel(string $id, mixed $value): string
    {
        $label = self::label($value);
        if (! Questionnaire::hasAnswer($value)) {
            return $label;
        }
        if (in_array($id, self::MONEY, true)) {
            return self::money($value);
        }
        if (in_array($id, ['availableBy', 'availableFrom', 'dueDate'], true) && is_string($value)) {
            return self::date($value);
        }
        if ($id === 'bedrooms') {
            $min = is_array($value) ? ($value['min'] ?? null) : null;
            $max = is_array($value) ? ($value['max'] ?? null) : null;
            $single = $value === 1 || $label === '1' || (($min === null || $min === 1) && ($max === null || $max === 1) && ($min !== null || $max !== null));
            $count = $min !== null && $max !== null && $min === $max ? (string) $min : ($max !== null && $min === null ? 'fino a '.$max : $label);

            return $count.' '.($single ? 'camera' : 'camere').' da letto';
        }

        return $id === 'area' ? $label.' m²' : $label;
    }

    public static function date(string $value): string
    {
        try {
            return Carbon::parse($value)->locale('it')->translatedFormat('j M Y');
        } catch (\Throwable) {
            return 'Da definire';
        }
    }

    /**
     * Stored answer → form field value (strings for inputs).
     *
     * @param  array<string, mixed>  $question
     */
    public static function toField(array $question, mixed $value): mixed
    {
        return match ($question['type']) {
            'boolean' => $value === true ? '1' : ($value === false ? '0' : ''),
            'multi' => array_values((array) ($value ?? [])),
            'range' => ['min' => (string) ($value['min'] ?? ''), 'max' => (string) ($value['max'] ?? '')],
            'financing' => ['amount' => (string) ($value['amount'] ?? ''), 'percent' => (string) ($value['percent'] ?? '')],
            'zone' => ['label' => (string) ($value['label'] ?? ''), 'lat' => (string) ($value['lat'] ?? ''), 'lng' => (string) ($value['lng'] ?? ''), 'radius' => (string) ($value['radius'] ?? '')],
            'amount' => $value === null ? '' : (string) $value,
            default => is_scalar($value) ? (string) $value : '',
        };
    }

    /**
     * Form field value → answer to send to request.answer (validation stays in AnswerValidator).
     * Empty fields become null ("Dato non ancora noto"). Non-numeric text is passed through so the
     * validator answers with the gestionale message.
     *
     * @param  array<string, mixed>  $question
     */
    public static function fromField(array $question, mixed $field): mixed
    {
        $num = function (mixed $v): mixed {
            $v = is_string($v) ? str_replace([' ', ','], ['', '.'], trim($v)) : $v;
            if ($v === '' || $v === null) {
                return null;
            }

            return is_numeric($v) ? ((string) (int) $v === (string) $v ? (int) $v : (float) $v) : $v;
        };
        $object = fn (array $keys) => ($o = array_filter(array_combine($keys, array_map(fn ($k) => $num($field[$k] ?? null), $keys)), fn ($v) => $v !== null)) === [] ? null : $o;

        return match ($question['type']) {
            'boolean' => $field === '1' || $field === true ? true : (($field === '0' || $field === false) ? false : null),
            // Tags come from the chip input as a list; a comma separated text is still accepted.
            'multi' => $question['id'] === 'tags' && is_string($field)
                ? (($t = array_values(array_filter(array_map('trim', explode(',', $field)), fn ($x) => $x !== ''))) === [] ? null : $t)
                : (($m = array_values(array_filter((array) $field, fn ($x) => is_string($x) && $x !== ''))) === [] ? null : $m),
            'range' => is_array($field) ? $object(['min', 'max']) : null,
            'financing' => is_array($field) ? $object(['amount', 'percent']) : null,
            'zone' => is_array($field) && ($z = $object(['lat', 'lng', 'radius'])) !== null
                ? array_filter(['label' => trim((string) ($field['label'] ?? '')) ?: null, ...$z], fn ($v) => $v !== null) : null,
            'amount' => $num($field),
            default => is_string($field) ? (trim($field) === '' ? null : ($question['type'] === 'text' ? $field : trim($field))) : $field,
        };
    }
}
