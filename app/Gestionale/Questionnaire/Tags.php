<?php

namespace App\Gestionale\Questionnaire;

use App\Gestionale\CommandRejected;
use Normalizer;

/**
 * engine.ts tags() + tags.ts normalizeTags()/tagKey(): free tags, normalized and deduplicated by meaning.
 */
final class Tags
{
    private const ALIASES = ['terrazzo' => 'terrazza', 'terrazzi' => 'terrazza', 'terrazze' => 'terrazza', 'arredata' => 'arredato',
        'arredati' => 'arredato', 'arredate' => 'arredato', 'ristrutturata' => 'ristrutturato', 'ristrutturati' => 'ristrutturato',
        'ristrutturate' => 'ristrutturato', 'box' => 'garage', 'box auto' => 'garage', 'accessibile' => 'senza barriere',
        'senza barriere architettoniche' => 'senza barriere'];

    /** @return list<string> */
    public static function validate(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > 30
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
            $tag = (string) preg_replace('/\s+/u', ' ', trim((string) preg_replace('/^#+/', '', $tag)));
            $key = self::key($tag);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $tag;
        }

        return $out;
    }

    public static function key(string $tag): string
    {
        $folded = mb_strtolower((string) preg_replace('/\p{Mn}/u', '', (string) Normalizer::normalize($tag, Normalizer::FORM_D)), 'UTF-8');
        $folded = trim((string) preg_replace('/[^a-z0-9]+/', ' ', $folded));

        return self::ALIASES[$folded] ?? $folded;
    }
}
