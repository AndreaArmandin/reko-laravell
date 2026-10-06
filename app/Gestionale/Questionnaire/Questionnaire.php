<?php

namespace App\Gestionale\Questionnaire;

use App\Models\QuestionSetVersion;

/**
 * Client profiling questionnaire (lib/crm/questions.ts, PROFILE_VERSION 5): paths by purpose,
 * conditional questions, guided vs manual questions, criteria cleaning and completeness.
 * Works on a question set (the agency's current QuestionSetVersion, else the platform one).
 * Questions keep the exact TS schema: {id, text, explanation, type, paths, order, options?,
 * skippable, active, field?, bucket?, weight, classification, operator?, condition?, sensitive?, collection}.
 */
final class Questionnaire
{
    public const PROFILE_VERSION = 5;

    public const CLASSIFICATIONS = ['Indispensabile', 'Preferibile', 'Indifferente', 'Da escludere'];

    /** purpose → path */
    public const PURPOSE_PATHS = [
        'Abitazione principale' => 'residential', 'Seconda casa' => 'residential', 'Investimento' => 'investment',
        'Attività commerciale' => 'commercial', 'Ufficio professionale' => 'commercial', 'Attività ricettiva' => 'commercial',
        'Uso industriale o artigianale' => 'commercial', 'Terreno' => 'land', 'Box o posto auto' => 'parking',
    ];

    public const PATH_LABELS = ['residential' => 'Residenziale', 'commercial' => 'Commerciale', 'investment' => 'Investimento',
        'land' => 'Terreno', 'parking' => 'Box e posti auto'];

    public const TYPOLOGIES = [
        'residential' => ['Appartamento', 'Attico', 'Villa', 'Casa indipendente'],
        'commercial' => ['Negozio', 'Ufficio', 'Laboratorio', 'Magazzino', 'Capannone', 'Hotel'],
        'investment' => ['Appartamento', 'Negozio', 'Ufficio', 'Intero edificio'],
        'land' => ['Terreno edificabile', 'Terreno agricolo'],
        'parking' => ['Box', 'Posto auto'],
    ];

    public const CORE_QUESTIONS = ['operation', 'purpose', 'typology', 'zones', 'budget', 'area'];

    public const PATH_QUESTIONS = [
        'residential' => ['bedrooms', 'condition', 'availableBy', 'currentHome', 'mortgage'],
        'commercial' => ['activity', 'catchment', 'condition', 'systems', 'availableBy'],
        'investment' => ['investmentStrategy', 'investmentDuration', 'occupancy', 'management', 'availableBy'],
        'land' => ['landProject', 'roadAccess', 'utilities', 'nearPoint', 'availableBy'],
        'parking' => ['parkingUse', 'coveredParking', 'roadAccess', 'charging', 'availableBy'],
    ];

    /** @var array<string, array<string, mixed>> */
    private array $byId = [];

    /** @param list<array<string, mixed>> $questions */
    public function __construct(private readonly array $questions)
    {
        foreach ($questions as $question) {
            $this->byId[$question['id']] = $question;
        }
    }

    /** Current set of the agency (highest published agency version, else the platform default). */
    public static function forAgency(?int $agencyId): self
    {
        return new self(QuestionSetVersion::current($agencyId)?->questions ?? self::defaultQuestions());
    }

    /** @return list<array<string, mixed>> */
    public static function defaultQuestions(): array
    {
        return json_decode((string) file_get_contents(resource_path('gestionale/questionario-v5.json')), true, 512, JSON_THROW_ON_ERROR)['questions'];
    }

    /** @return list<array<string, mixed>> */
    public function questions(): array
    {
        return $this->questions;
    }

    /** @return array<string, mixed>|null */
    public function question(string $id): ?array
    {
        return $this->byId[$id] ?? null;
    }

    /** Active, non-archived question (for the quick request). */
    public function isActive(string $id): bool
    {
        $q = $this->byId[$id] ?? null;

        return $q !== null && $q['active'] && ($q['collection'] ?? '') !== 'archived';
    }

    /** @return list<string> configured zones (per agency; empty by default) */
    public function zones(): array
    {
        return array_values($this->byId['zones']['options'] ?? []);
    }

    /** @param array<string, mixed> $criteria */
    public static function pathFor(array $criteria): string
    {
        return self::PURPOSE_PATHS[is_string($criteria['purpose'] ?? null) ? $criteria['purpose'] : ''] ?? 'residential';
    }

    /**
     * @param  array<string, mixed>  $criteria
     * @return list<string>
     */
    public static function typologies(array $criteria): array
    {
        return self::TYPOLOGIES[self::pathFor($criteria)];
    }

    /** @return list<string> union of all typologies (quick-request.ts quickTypologies) */
    public static function quickTypologies(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::TYPOLOGIES))));
    }

    /**
     * Options to show for a question (typology depends on the purpose path).
     *
     * @param  array<string, mixed>  $question
     * @param  array<string, mixed>  $criteria
     * @return list<string>|null
     */
    public function options(array $question, array $criteria): ?array
    {
        return $question['id'] === 'typology' ? self::typologies($criteria) : ($question['options'] ?? null);
    }

    /**
     * questions.ts visibleQuestions()
     *
     * @param  array<string, mixed>  $criteria
     * @return list<array<string, mixed>>
     */
    public function visible(array $criteria): array
    {
        $path = self::pathFor($criteria);

        $relevant = function (array $q, array $ancestors = []) use (&$relevant, $path, $criteria): bool {
            if (! $q['active'] || ($q['collection'] ?? '') === 'archived' || in_array($q['id'], $ancestors, true)
                || ($q['paths'] !== 'all' && ! in_array($path, (array) $q['paths'], true))) {
                return false;
            }
            // Selling the present home only affects this flow when purchasing, not renting.
            if ($q['id'] === 'sellCurrentHome' && ($criteria['operation'] ?? null) !== 'Acquisto') {
                return false;
            }
            if (empty($q['condition'])) {
                return true;
            }
            $field = $q['condition']['field'];
            if (! in_array($criteria[$field] ?? null, $q['condition']['values'], true)) {
                return false;
            }
            $parent = $this->byId[$field] ?? null;

            return $parent === null || $relevant($parent, [...$ancestors, $q['id']]);
        };

        $visible = array_values(array_filter($this->questions, fn ($q) => $relevant($q)));
        usort($visible, fn ($a, $b) => $a['order'] <=> $b['order']);

        return $visible;
    }

    /**
     * questions.ts guidedQuestions(): core + the path's 5 details (mortgage → rentalDuration when renting),
     * custom guided questions, then notes. Until operation is answered, the purchase outline is used.
     *
     * @param  array<string, mixed>  $criteria
     * @return list<array<string, mixed>>
     */
    public function guided(array $criteria): array
    {
        $details = array_map(
            fn ($id) => $id === 'mortgage' && ($criteria['operation'] ?? null) === 'Locazione' ? 'rentalDuration' : $id,
            self::PATH_QUESTIONS[self::pathFor($criteria)],
        );
        $known = array_values(array_unique([...self::CORE_QUESTIONS, ...array_merge(...array_values(self::PATH_QUESTIONS)), 'rentalDuration', 'notes']));
        $relevant = $this->visible(Questionnaire::hasAnswer($criteria['operation'] ?? null) ? $criteria : [...$criteria, 'operation' => 'Acquisto']);
        $byId = array_column($relevant, null, 'id');
        $custom = array_column(array_filter($relevant,
            fn ($q) => ($q['collection'] ?? '') === 'guided' && ! in_array($q['id'], $known, true)), 'id');

        $out = [];
        foreach (array_unique([...self::CORE_QUESTIONS, ...$details, ...$custom, 'notes']) as $id) {
            if (isset($byId[$id]) && ($byId[$id]['collection'] ?? '') !== 'manual') {
                $out[] = $byId[$id];
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $criteria
     * @return list<array<string, mixed>>
     */
    public function manual(array $criteria): array
    {
        $guided = array_column($this->guided($criteria), 'id');

        return array_values(array_filter($this->visible($criteria), fn ($q) => ! in_array($q['id'], $guided, true)));
    }

    /** questions.ts hasAnswer(): null, '', [] and objects without any value are not answers. */
    public static function hasAnswer(mixed $value): bool
    {
        if ($value === null || $value === '' || $value === []) {
            return false;
        }
        if (is_array($value) && ! array_is_list($value)) {
            return array_filter($value, fn ($v) => $v !== null && $v !== '') !== [];
        }

        return true;
    }

    /**
     * questions.ts cleanCriteria(): keep visible answers and the archived ones (history is never lost).
     *
     * @param  array<string, mixed>  $criteria
     * @return array<string, mixed>
     */
    public function clean(array $criteria): array
    {
        $allowed = array_column($this->visible($criteria), 'id');
        foreach ($this->questions as $q) {
            if (($q['collection'] ?? '') === 'archived') {
                $allowed[] = $q['id'];
            }
        }

        return array_filter($criteria, fn ($key) => in_array($key, $allowed, true), ARRAY_FILTER_USE_KEY);
    }

    /**
     * @param  array<string, mixed>  $criteria
     * @return array{answered: int, total: int, percent: int}
     */
    public function completeness(array $criteria): array
    {
        return self::ratio($this->guided($criteria), $criteria);
    }

    /**
     * @param  list<array<string, mixed>>  $questions
     * @param  array<string, mixed>  $criteria
     * @return array{answered: int, total: int, percent: int}
     */
    public static function ratio(array $questions, array $criteria): array
    {
        $answered = count(array_filter($questions, fn ($q) => self::hasAnswer($criteria[$q['id']] ?? null)));
        $total = count($questions);

        return ['answered' => $answered, 'total' => $total, 'percent' => $total ? (int) round($answered / $total * 100) : 0];
    }

    /**
     * Automatic title (engine.ts request.answer / quick request), e.g. "Attico da acquistare · Isola".
     *
     * @param  array<string, mixed>  $criteria
     */
    public static function autoTitle(array $criteria): string
    {
        $typology = $criteria['typology'] ?? null;
        $zones = $criteria['zones'] ?? null;

        return implode(' ', array_filter([
            is_array($typology) ? (implode(' / ', $typology) ?: 'Immobile') : ((is_string($typology) && $typology !== '') ? $typology : 'Immobile'),
            match ($criteria['operation'] ?? null) {
                'Acquisto' => 'da acquistare',
                'Locazione' => 'in affitto',
                default => '',
            },
            is_array($zones) ? '· '.implode(', ', $zones) : '',
        ], fn ($p) => $p !== ''));
    }
}
