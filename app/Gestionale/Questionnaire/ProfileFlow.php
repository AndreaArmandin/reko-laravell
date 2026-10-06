<?php

namespace App\Gestionale\Questionnaire;

/**
 * profile-flow.ts: the 5 pages of the profiling wizard (presentation only, never migrates data).
 */
final class ProfileFlow
{
    public const OPTIONAL_IDS = ['currentHome', 'sellCurrentHome', 'mortgage', 'mortgageState', 'mortgageEstimate'];

    public const GROUPS = [
        ['id' => 'operation', 'title' => 'Acquisto o affitto e finalità', 'ids' => ['operation', 'purpose']],
        ['id' => 'typology', 'title' => 'Cosa e dove', 'ids' => ['typology', 'zones', 'nearPoint']],
        ['id' => 'budget', 'title' => 'Dimensioni e budget', 'ids' => ['budget', 'area', 'bedrooms']],
        ['id' => 'availableBy', 'title' => 'Condizioni e tempistica', 'ids' => ['condition', 'availableBy']],
        ['id' => 'notes', 'title' => 'Note e tag', 'ids' => ['notes', 'tags']],
    ];

    /**
     * @param  array<string, mixed>  $criteria
     * @return list<array{id: string, title: string, questions: list<array<string, mixed>>}>
     */
    public static function groups(Questionnaire $q, array $criteria): array
    {
        $questions = array_values(array_filter($q->guided($criteria), fn ($x) => ! in_array($x['id'], self::OPTIONAL_IDS, true)));
        $tags = array_values(array_filter($q->visible($criteria), fn ($x) => $x['id'] === 'tags'))[0] ?? null;
        if ($tags && ! in_array('tags', array_column($questions, 'id'), true)) {
            $questions[] = $tags;
        }
        $known = array_merge(...array_column(self::GROUPS, 'ids'));

        return array_map(fn ($group) => [
            'id' => $group['id'],
            'title' => $group['title'],
            'questions' => array_values(array_filter($questions,
                fn ($x) => in_array($x['id'], $group['ids'], true) || ($group['id'] === 'availableBy' && ! in_array($x['id'], $known, true)))),
        ], self::GROUPS);
    }

    /**
     * @param  array<string, mixed>  $criteria
     * @return list<array<string, mixed>>
     */
    public static function optionalQuestions(Questionnaire $q, array $criteria): array
    {
        return array_values(array_filter($q->visible($criteria), fn ($x) => in_array($x['id'], self::OPTIONAL_IDS, true)));
    }

    /** Page to resume from the saved step_id. @param array<string, mixed> $criteria */
    public static function groupId(Questionnaire $q, array $criteria, string $legacyStep): string
    {
        if (! Questionnaire::hasAnswer($criteria['operation'] ?? null)) {
            return 'operation';
        }
        if (in_array($legacyStep, self::OPTIONAL_IDS, true)) {
            return 'notes';
        }
        foreach (self::groups($q, $criteria) as $group) {
            if ($group['id'] === $legacyStep || in_array($legacyStep, array_column($group['questions'], 'id'), true)) {
                return $group['id'];
            }
        }

        return 'operation';
    }

    /**
     * Progress shown on lists and wizard (tags excluded, as in the original indicator).
     *
     * @param  array<string, mixed>  $criteria
     * @return array{answered: int, total: int, percent: int}
     */
    public static function baseCompleteness(Questionnaire $q, array $criteria): array
    {
        $questions = array_values(array_filter(array_merge(...array_column(self::groups($q, $criteria), 'questions')), fn ($x) => $x['id'] !== 'tags'));

        return Questionnaire::ratio($questions, $criteria);
    }
}
