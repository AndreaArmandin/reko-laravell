<?php

namespace App\Gestionale\Properties;

use App\Gestionale\Questionnaire\AnswerPresenter;
use App\Gestionale\Questionnaire\Questionnaire;
use App\Gestionale\Questionnaire\Tags;
use App\Models\GestionaleSetting;
use App\Models\Property;
use App\Models\PropertyMatch;
use App\Models\PropertyRequest;
use Illuminate\Support\Facades\DB;

/**
 * Port of gestionale/lib/crm/matching.ts (matchScore) and engine.ts recalculate(): every request is compared
 * with every property of the agency, whatever their status. Personal and practice-only fields never affect
 * compatibility. The match workflow fields (status, feedback, note, next action, visit) survive a recalculation.
 */
final class PropertyMatcher
{
    public const DEFAULT_WEIGHTS = ['zone' => 20, 'budget' => 20, 'type' => 15, 'surface' => 10, 'rooms' => 10, 'required' => 15, 'preferred' => 5, 'availability' => 5];

    public const DEFAULT_NEXT_ACTION = 'Valuta i requisiti e il prossimo passo';

    /**
     * What a score needs from the agency, loaded once per recalculation: questionnaire, weights, settings version.
     *
     * @return array{questions: Questionnaire, weights: array<string, int|float>, version: int}
     */
    public function context(int $agencyId): array
    {
        $settings = GestionaleSetting::query()->where('agency_id', $agencyId)->first();

        return [
            'questions' => Questionnaire::forAgency($agencyId),
            'weights' => array_replace(self::DEFAULT_WEIGHTS, $settings?->matching_weights ?? []),
            'version' => (int) ($settings?->version ?? 1),
        ];
    }

    /**
     * @param  array{questions: Questionnaire, weights: array<string, int|float>, version: int}|null  $context
     * @return array{score:?int, confidence:string, compared:int, answered:int, coverage:float, conditional:bool, comparisons:array, version:int, reasons:array, level:string}
     */
    public function score(PropertyRequest $request, Property $property, ?array $context = null): array
    {
        $context ??= $this->context((int) $request->agency_id);
        $questions = $context['questions'];
        $criteria = $request->criteria ?? [];
        $classifications = $request->classifications ?? [];
        $comparisons = [];
        $questionWeights = [];

        foreach ($questions->visible($criteria) as $question) {
            $id = (string) $question['id'];
            $saved = $criteria[$id] ?? null;
            $expected = ($question['type'] ?? '') === 'range' && (is_int($saved) || is_float($saved))
                ? (in_array($id, ['budget', 'fees'], true) ? ['max' => $saved] : ['min' => $saved])
                : $saved;
            $classification = $classifications[$id] ?? $question['classification'] ?? 'Preferibile';
            if (! ($question['field'] ?? null) || ($question['sensitive'] ?? false) || ! Questionnaire::hasAnswer($expected) || $classification === 'Indifferente') {
                continue;
            }
            $field = (string) $question['field'];

            if ($field === 'tags' && is_array($expected)) {
                $tags = Tags::normalize(array_values(array_filter($expected, 'is_string')));
                foreach ($tags as $tag) {
                    $evidence = Tags::evidence($property, $tag);
                    // A tag already covered by its structured question (same value, same priority) is not counted twice.
                    $structured = $evidence['field'] ? $questions->question($evidence['field']) : null;
                    if ($structured !== null && ($criteria[$structured['id']] ?? null) === $evidence['requested']
                        && ($classifications[$structured['id']] ?? $structured['classification']) === $classification) {
                        continue;
                    }
                    $status = $evidence['status'] === 'unknown' || $classification !== 'Da escludere'
                        ? $evidence['status'] : ($evidence['status'] === 'satisfied' ? 'missing' : 'satisfied');
                    $tagId = 'tags:'.Tags::key($tag);
                    $comparisons[] = [
                        'id' => $tagId, 'label' => 'Tag: '.$tag,
                        'bucket' => in_array($classification, ['Indispensabile', 'Da escludere'], true) ? 'required' : 'preferred',
                        'classification' => $classification, 'status' => $status, 'expected' => $tag, 'actual' => $evidence['actual'],
                        'value' => $status === 'satisfied' ? 1 : 0,
                    ];
                    $questionWeights[$tagId] = (float) ($question['weight'] ?? 1) / max(1, count($tags));
                }

                continue;
            }

            $actual = match ($field) {
                'zones' => $property->zone,
                'coordinates' => $this->distanceMatches($property, $expected),
                default => ($property->features ?? [])[$field] ?? null,
            };
            if ($id === 'works') {
                $condition = ($property->features ?? [])['condition'] ?? null;
                $actual = Questionnaire::hasAnswer($condition) ? $condition === 'Da ristrutturare' : null;
            }
            $incompatibleContract = $field === 'price' && Questionnaire::hasAnswer($criteria['operation'] ?? null)
                && $criteria['operation'] !== (($property->features ?? [])['operation'] ?? null);
            $known = Questionnaire::hasAnswer($actual) && ! $incompatibleContract;
            $satisfied = false;
            if ($known) {
                if ($id === 'works') {
                    $satisfied = $expected === true || $actual === false;
                } else {
                    $satisfied = $this->compare($expected, $actual, (string) ($question['operator'] ?? 'equal'));
                }
                if ($classification === 'Da escludere') {
                    $satisfied = ! $satisfied;
                }
            }
            $bucket = ($question['bucket'] ?? 'preferred') === 'preferred' && in_array($classification, ['Indispensabile', 'Da escludere'], true)
                ? 'required' : ($question['bucket'] ?? 'preferred');
            $comparisons[] = [
                'id' => $id, 'label' => $question['text'], 'bucket' => $bucket, 'classification' => $classification,
                'status' => ! $known ? 'unknown' : ($satisfied ? 'satisfied' : 'missing'), 'expected' => AnswerPresenter::label($expected),
                'actual' => $incompatibleContract ? 'Contratto diverso: importi non confrontabili' : ($field === 'coordinates' ? ($actual === null ? 'Da definire' : trim((string) $property->zone).' · entro 0 km') : AnswerPresenter::label($actual)),
                'value' => $satisfied ? 1 : 0,
            ];
            $questionWeights[$id] = (float) ($question['weight'] ?? 1);
        }

        $numerator = $denominator = 0.0;
        foreach ($context['weights'] as $bucket => $groupWeight) {
            $group = array_values(array_filter($comparisons, fn ($item) => $item['bucket'] === $bucket && $item['status'] !== 'unknown'));
            $total = array_sum(array_map(fn ($item) => $questionWeights[$item['id']] ?? 1, $group));
            if ($total <= 0 || (float) $groupWeight <= 0) {
                continue;
            }
            $denominator += (float) $groupWeight;
            $numerator += (float) $groupWeight * array_sum(array_map(fn ($item) => $item['value'] * ($questionWeights[$item['id']] ?? 1), $group)) / $total;
        }
        $compared = count(array_filter($comparisons, fn ($item) => $item['status'] !== 'unknown'));
        $coverage = count($comparisons) ? $compared / count($comparisons) : 0;
        $score = $denominator > 0 ? (int) round(100 * $numerator / $denominator) : null;
        $reasons = array_map(fn ($item) => ($item['status'] === 'unknown' ? 'Da verificare' : ($item['classification'] === 'Indispensabile' ? 'Requisito indispensabile non soddisfatto' : ($item['classification'] === 'Da escludere' ? 'Elemento da escludere presente' : 'Non coincide'))).': '.$item['label'], array_filter($comparisons, fn ($item) => $item['status'] !== 'satisfied'));

        return [
            'score' => $score,
            'confidence' => $compared >= 10 && $coverage >= .8 ? 'Alta' : ($compared >= 5 && $coverage >= .6 ? 'Media' : 'Indicativa'),
            'compared' => $compared, 'answered' => count($comparisons), 'coverage' => $coverage,
            'conditional' => (bool) array_filter($comparisons, fn ($item) => $item['status'] !== 'satisfied' && in_array($item['classification'], ['Indispensabile', 'Da escludere'], true)),
            'comparisons' => $comparisons, 'version' => $context['version'], 'reasons' => array_values($reasons),
            'level' => $score === null ? 'Da valutare' : ($score >= 80 ? 'Alta' : ($score >= 60 ? 'Buona' : ($score >= 40 ? 'Parziale' : 'Bassa'))),
        ];
    }

    /** One request against every property of the agency (status and archive never exclude a property, as in engine.ts recalculate). */
    public function refreshRequest(PropertyRequest $request): void
    {
        $context = $this->context((int) $request->agency_id);
        DB::transaction(function () use ($request, $context) {
            Property::query()->where('agency_id', $request->agency_id)->orderBy('id')
                ->chunkById(100, function ($properties) use ($request, $context) {
                    foreach ($properties as $property) {
                        $this->store($request, $property, $this->score($request, $property, $context));
                    }
                });
        });
    }

    /** One property against every request of the agency. */
    public function refreshProperty(Property $property): void
    {
        $context = $this->context((int) $property->agency_id);
        PropertyRequest::query()->where('agency_id', $property->agency_id)->orderBy('id')->chunkById(100,
            function ($requests) use ($property, $context) {
                foreach ($requests as $request) {
                    $this->store($request, $property, $this->score($request, $property, $context));
                }
            });
    }

    /** Every request against every property (settings or questionnaire changed). */
    public function refreshAgency(int $agencyId): void
    {
        PropertyRequest::query()->where('agency_id', $agencyId)->orderBy('id')->each(fn ($request) => $this->refreshRequest($request));
    }

    /** @param array<string, mixed> $result */
    private function store(PropertyRequest $request, Property $property, array $result): void
    {
        $match = PropertyMatch::query()->firstOrNew(['agency_id' => $request->agency_id, 'property_id' => $property->id, 'property_request_id' => $request->id]);
        if (! $match->exists) {
            $match->next_action = self::DEFAULT_NEXT_ACTION;
        }
        $match->score = $result['score'];
        $match->result = $result;
        $match->updated_at = now();
        $match->save();
    }

    private function distanceMatches(Property $property, mixed $expected): ?bool
    {
        if (! is_array($expected) || ! isset($expected['lat'], $expected['lng'], $expected['radius'])) {
            return null;
        }
        $result = DB::selectOne('SELECT ST_DWithin(location::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?) AS matches FROM properties WHERE id = ? AND agency_id = ? AND location IS NOT NULL',
            [(float) $expected['lng'], (float) $expected['lat'], (float) $expected['radius'] * 1000, $property->id, $property->agency_id]);

        return $result === null ? null : (bool) $result->matches;
    }

    /** matching.ts compare(). */
    private function compare(mixed $expected, mixed $actual, string $operator): bool
    {
        if ($operator === 'maximum') {
            return is_numeric($actual) && (float) $actual <= (float) $expected;
        }
        if ($operator === 'minimum') {
            return is_numeric($actual) && (float) $actual >= (float) $expected;
        }
        if ($operator === 'date') {
            return mb_substr($this->text($actual), 0, 10) <= mb_substr($this->text($expected), 0, 10);
        }
        if ($operator === 'distance') {
            return $actual === true;
        }
        if ($operator === 'range' && is_array($expected) && ! array_is_list($expected)) {
            return (! isset($expected['min']) || (is_numeric($actual) && (float) $actual >= (float) $expected['min']))
                && (! isset($expected['max']) || (is_numeric($actual) && (float) $actual <= (float) $expected['max']));
        }
        if (is_array($expected)) {
            if (in_array('Tutta Milano', $expected, true)) {
                return true;
            }

            return is_array($actual) ? array_diff($expected, $actual) === [] : in_array($this->text($actual), $expected, true);
        }

        return is_array($actual) ? in_array($this->text($expected), $actual, true) : mb_strtolower($this->text($actual)) === mb_strtolower($this->text($expected));
    }

    /** JavaScript String() for scalars. */
    private function text(mixed $value): string
    {
        return match (true) {
            $value === true => 'true',
            $value === false => 'false',
            $value === null => 'null',
            is_array($value) => implode(',', $value),
            default => (string) $value,
        };
    }
}
