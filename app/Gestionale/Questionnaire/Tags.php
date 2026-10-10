<?php

namespace App\Gestionale\Questionnaire;

use App\Gestionale\CommandRejected;
use App\Models\Property;
use Normalizer;

/**
 * engine.ts tags() + tags.ts: free tags (normalizeTags, tagKey, tagSuggestions) and tagEvidence():
 * what a property sheet says about a requested tag, from the sheet's own tags, its structured
 * features and the textual description. Conflicting evidence stays "unknown", never a guess.
 */
final class Tags
{
    public const SUGGESTIONS = ['Arredato', 'Ristrutturato', 'Da ristrutturare', 'Terrazza', 'Giardino', 'Garage', 'Senza barriere'];

    /** The tag input accepts at most 30 tags of 60 characters (tag-input.tsx, engine.ts tags()). */
    public const MAX_TAGS = 30;

    private const ALIASES = ['terrazzo' => 'terrazza', 'terrazzi' => 'terrazza', 'terrazze' => 'terrazza', 'arredata' => 'arredato',
        'arredati' => 'arredato', 'arredate' => 'arredato', 'ristrutturata' => 'ristrutturato', 'ristrutturati' => 'ristrutturato',
        'ristrutturate' => 'ristrutturato', 'box' => 'garage', 'box auto' => 'garage', 'accessibile' => 'senza barriere',
        'senza barriere architettoniche' => 'senza barriere'];

    /** @var array<string, array{field: string, value: bool|string, phrases: list<string>}> */
    private const DEFINITIONS = [
        'arredato' => ['field' => 'furnished', 'value' => true, 'phrases' => ['arredato', 'arredata', 'arredati', 'arredate']],
        'non arredato' => ['field' => 'furnished', 'value' => false, 'phrases' => ['non arredato', 'non arredata', 'senza arredi']],
        'terrazza' => ['field' => 'terrace', 'value' => true, 'phrases' => ['terrazza', 'terrazzo', 'terrazzi', 'terrazze']],
        'giardino' => ['field' => 'garden', 'value' => true, 'phrases' => ['giardino']],
        'garage' => ['field' => 'garage', 'value' => true, 'phrases' => ['garage', 'box auto', 'box']],
        'balcone' => ['field' => 'balcony', 'value' => true, 'phrases' => ['balcone', 'balconi']],
        'ascensore' => ['field' => 'elevator', 'value' => true, 'phrases' => ['ascensore']],
        'senza barriere' => ['field' => 'accessible', 'value' => true, 'phrases' => ['senza barriere', 'privo di barriere', 'priva di barriere', 'accessibile senza gradini']],
        'ristrutturato' => ['field' => 'condition', 'value' => 'Ristrutturato', 'phrases' => ['ristrutturato', 'ristrutturata', 'ristrutturati', 'ristrutturate']],
        'da ristrutturare' => ['field' => 'condition', 'value' => 'Da ristrutturare', 'phrases' => ['da ristrutturare']],
    ];

    /** @return list<string> */
    public static function validate(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > self::MAX_TAGS
            || array_filter($value, fn ($t) => ! is_string($t) || trim($t) === '' || mb_strlen($t) > 60) !== []) {
            throw new CommandRejected('Usa fino a 30 tag, con un massimo di 60 caratteri ciascuno.', 400, 'value');
        }

        return self::normalize($value);
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    public static function normalize(array $values): array
    {
        $seen = [];
        $out = [];
        foreach ($values as $tag) {
            $tag = (string) preg_replace('/\s+/u', ' ', trim((string) preg_replace('/^#+/', '', (string) $tag)));
            $key = self::key($tag);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $tag;
        }

        return $out;
    }

    /** tags.ts fold(): no accents, lower case, only letters and digits separated by single spaces. */
    public static function fold(string $text): string
    {
        $folded = mb_strtolower((string) preg_replace('/\p{Mn}/u', '', (string) Normalizer::normalize($text, Normalizer::FORM_D)), 'UTF-8');

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $folded));
    }

    public static function key(string $tag): string
    {
        $folded = self::fold($tag);

        return self::ALIASES[$folded] ?? $folded;
    }

    /**
     * tags.ts tagEvidence(): status satisfied / missing / unknown with the reason shown to the operator.
     *
     * @return array{status: string, actual: string, field: ?string, requested: bool|string|null}
     */
    public static function evidence(Property $property, string $tag): array
    {
        $key = self::key($tag);
        $definition = self::DEFINITIONS[$key] ?? null;
        $features = (array) ($property->features ?? []);
        $field = $definition['field'] ?? null;
        $requested = $definition['value'] ?? null;

        $explicit = false;
        if (isset($features['tags']) && is_array($features['tags'])) {
            foreach ($features['tags'] as $value) {
                if (is_string($value) && self::key($value) === $key) {
                    $explicit = true;
                    break;
                }
            }
        }
        $mentioned = self::textualEvidence(($property->description ?? '').'. '.($property->strengths ?? ''), $definition['phrases'] ?? [$key]);
        $raw = $definition ? ($features[$definition['field']] ?? null) : null;

        // "Ottimo" and "Nuovo" do not certify that a property was renovated.
        $structured = null;
        if ($definition && is_bool($raw)) {
            $structured = $raw === $definition['value'];
        } elseif ($definition && $definition['field'] === 'condition' && is_string($raw)) {
            $structured = self::fold($raw) === self::fold((string) $definition['value']) ? true
                : (($key === 'da ristrutturare' || $raw === 'Da ristrutturare') ? false : null);
        }

        $values = array_values(array_filter([$explicit ? true : null, $structured, $mentioned], fn ($v) => $v !== null));
        if (array_filter($values, fn ($v) => $v !== $values[0]) !== []) {
            return ['status' => 'unknown', 'actual' => 'Dati discordanti tra tag, caratteristiche e descrizione', 'field' => $field, 'requested' => $requested];
        }
        if ($values === []) {
            return ['status' => 'unknown', 'actual' => 'Non indicato nella scheda immobile', 'field' => $field, 'requested' => $requested];
        }

        return [
            'status' => $values[0] ? 'satisfied' : 'missing',
            'actual' => $structured !== null
                ? 'Caratteristica in scheda: '.(is_bool($raw) ? ($raw ? 'Sì' : 'No') : (string) $raw)
                : ($explicit ? 'Tag presente nella scheda immobile' : ($values[0] ? 'Corrispondenza testuale nella descrizione' : 'La descrizione esclude questa caratteristica')),
            'field' => $field,
            'requested' => $requested,
        ];
    }

    /**
     * tags.ts textualEvidence(): true / false when every mention agrees, null when absent or contradictory.
     * Tentative mentions ("possibilità di", "in progetto") are ignored; "senza", "non", "assenza di" negate.
     *
     * @param  list<string>  $phrases
     */
    private static function textualEvidence(string $description, array $phrases): ?bool
    {
        $text = ' '.self::fold($description).' ';
        $findings = [];
        foreach ($phrases as $phrase) {
            $needle = ' '.self::fold($phrase).' ';
            $from = 0;
            while (($index = strpos($text, $needle, $from)) !== false) {
                $start = max(0, $index - 65);
                $before = trim(substr($text, $start, $index - $start));
                $after = substr($text, $index + strlen($needle), 35);
                $tentative = preg_match('/(?:possibilita|eventuale|ipotetico|ipotetica|parzialmente|parziale|predisposizione)(?:\s+\w+){0,4}$/', $before) === 1
                    || preg_match('/^(?:in progetto|da realizzare|parziale|parzialmente)\b/', $after) === 1;
                if (! $tentative) {
                    $findings[] = preg_match('/(?:non|no|senza|privo di|priva di|assenza di)(?:\s+(?:un|una|il|lo|la|di|del|della|alcun|alcuna|c|e|presente|dispone|dotato|dotata|completamente)){0,3}$/', $before) !== 1;
                }
                $from = $index + strlen($needle) - 1;
            }
        }
        if ($findings === []) {
            return null;
        }
        foreach ($findings as $finding) {
            if ($finding !== $findings[0]) {
                return null;
            }
        }

        return $findings[0];
    }
}
