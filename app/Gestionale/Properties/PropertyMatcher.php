<?php

namespace App\Gestionale\Properties;

use App\Gestionale\Questionnaire\Questionnaire;
use App\Models\GestionaleSetting;
use App\Models\Property;
use App\Models\PropertyMatch;
use App\Models\PropertyRequest;
use Illuminate\Support\Facades\DB;

/** Port of gestionale/lib/crm/matching.ts. Personal and practice-only fields never affect compatibility. */
final class PropertyMatcher
{
    private const WEIGHTS = ['zone' => 20, 'budget' => 20, 'type' => 15, 'surface' => 10, 'rooms' => 10, 'required' => 15, 'preferred' => 5, 'availability' => 5];

    /** @return array{score:?int, confidence:string, compared:int, answered:int, coverage:float, conditional:bool, comparisons:array, version:int, reasons:array, level:string} */
    public function score(PropertyRequest $request, Property $property): array
    {
        $questions = Questionnaire::forAgency((int) $request->agency_id);
        $settings = GestionaleSetting::query()->where('agency_id', $request->agency_id)->first();
        $weights = array_replace(self::WEIGHTS, $settings?->matching_weights ?? []);
        $comparisons = [];
        $questionWeights = [];

        foreach ($questions->visible($request->criteria ?? []) as $question) {
            $id = (string) $question['id'];
            $expected = $request->criteria[$id] ?? null;
            $classification = $request->classifications[$id] ?? $question['classification'] ?? 'Preferibile';
            if (! ($question['field'] ?? null) || ($question['sensitive'] ?? false) || ! Questionnaire::hasAnswer($expected) || $classification === 'Indifferente') continue;
            if (($question['type'] ?? '') === 'range' && is_numeric($expected)) {
                $expected = in_array($id, ['budget', 'fees'], true) ? ['max' => (float) $expected] : ['min' => (float) $expected];
            }

            $field = (string) $question['field'];
            $actual = match ($field) {
                'zones' => $property->zone ?: $property->city,
                'coordinates' => $this->distanceMatches($property, $expected),
                default => $property->features[$field] ?? null,
            };
            if ($id === 'works') $actual = isset($property->features['condition']) ? $property->features['condition'] === 'Da ristrutturare' : null;
            $incompatibleContract = $field === 'price' && Questionnaire::hasAnswer($request->criteria['operation'] ?? null)
                && $request->criteria['operation'] !== ($property->features['operation'] ?? null);
            $known = Questionnaire::hasAnswer($actual) && ! $incompatibleContract;
            $satisfied = $known && $this->compare($expected, $actual, (string) ($question['operator'] ?? 'equal'));
            if ($classification === 'Da escludere' && $known) $satisfied = ! $satisfied;
            $bucket = ($question['bucket'] ?? 'preferred') === 'preferred' && in_array($classification, ['Indispensabile', 'Da escludere'], true)
                ? 'required' : ($question['bucket'] ?? 'preferred');
            $status = ! $known ? 'unknown' : ($satisfied ? 'satisfied' : 'missing');
            $comparisons[] = [
                'id' => $id, 'label' => $question['text'], 'bucket' => $bucket, 'classification' => $classification,
                'status' => $status, 'expected' => $this->label($expected),
                'actual' => $incompatibleContract ? 'Contratto diverso: importi non confrontabili' : $this->label($actual), 'value' => $satisfied ? 1 : 0,
            ];
            $questionWeights[$id] = (float) ($question['weight'] ?? 1);
        }

        $numerator = $denominator = 0.0;
        foreach ($weights as $bucket => $groupWeight) {
            $group = array_values(array_filter($comparisons, fn ($item) => $item['bucket'] === $bucket && $item['status'] !== 'unknown'));
            $total = array_sum(array_map(fn ($item) => $questionWeights[$item['id']] ?? 1, $group));
            if ($total <= 0 || (float) $groupWeight <= 0) continue;
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
            'comparisons' => $comparisons, 'version' => 1, 'reasons' => array_values($reasons),
            'level' => $score === null ? 'Da valutare' : ($score >= 80 ? 'Alta' : ($score >= 60 ? 'Buona' : ($score >= 40 ? 'Parziale' : 'Bassa'))),
        ];
    }

    public function refreshRequest(PropertyRequest $request): void
    {
        DB::transaction(function () use ($request) {
            Property::query()->where('agency_id', $request->agency_id)->whereNull('lifecycle_state')->whereIn('status', ['Attivo', 'In trattativa'])
                ->orderBy('id')->chunkById(100, function ($properties) use ($request) {
                    foreach ($properties as $property) {
                        $result = $this->score($request, $property);
                        PropertyMatch::query()->updateOrCreate(
                            ['agency_id' => $request->agency_id, 'property_id' => $property->id, 'property_request_id' => $request->id],
                            ['score' => $result['score'], 'result' => $result, 'updated_at' => now()],
                        );
                    }
                });
        });
    }

    public function refreshProperty(Property $property): void
    {
        PropertyRequest::query()->where('agency_id', $property->agency_id)->whereNull('lifecycle_state')->orderBy('id')->chunkById(100,
            function ($requests) use ($property) {
                foreach ($requests as $request) {
                    $result = $this->score($request, $property);
                    PropertyMatch::query()->updateOrCreate(
                        ['agency_id' => $property->agency_id, 'property_id' => $property->id, 'property_request_id' => $request->id],
                        ['score' => $result['score'], 'result' => $result, 'updated_at' => now()],
                    );
                }
            });
    }

    private function distanceMatches(Property $property, mixed $expected): ?bool
    {
        if (! is_array($expected) || ! isset($expected['lat'], $expected['lng'], $expected['radius'])) return null;
        $result = DB::selectOne('SELECT ST_DWithin(location::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?) AS matches FROM properties WHERE id = ? AND agency_id = ? AND location IS NOT NULL',
            [(float) $expected['lng'], (float) $expected['lat'], (float) $expected['radius'] * 1000, $property->id, $property->agency_id]);
        return $result === null ? null : (bool) $result->matches;
    }

    private function compare(mixed $expected, mixed $actual, string $operator): bool
    {
        if ($operator === 'maximum') return is_numeric($actual) && (float) $actual <= (float) $expected;
        if ($operator === 'minimum') return is_numeric($actual) && (float) $actual >= (float) $expected;
        if ($operator === 'date') return (string) $actual <= (string) $expected;
        if ($operator === 'distance') return $actual === true;
        if ($operator === 'range' && is_array($expected)) {
            return (! isset($expected['min']) || (float) $actual >= (float) $expected['min'])
                && (! isset($expected['max']) || (float) $actual <= (float) $expected['max']);
        }
        if (is_array($expected)) {
            if (in_array('Tutta Milano', $expected, true)) return true;
            return is_array($actual) ? array_diff($expected, $actual) === [] : in_array(mb_strtolower((string) $actual), array_map(fn ($item) => mb_strtolower((string) $item), $expected), true);
        }
        return is_array($actual) ? in_array((string) $expected, $actual, true) : mb_strtolower((string) $actual) === mb_strtolower((string) $expected);
    }

    private function label(mixed $value): string
    {
        if ($value === null || $value === '') return 'Da definire';
        if ($value === true) return 'Sì';
        if ($value === false) return 'No';
        if (is_array($value)) {
            if (array_is_list($value)) return implode(', ', $value);
            return implode(' ', array_filter([isset($value['min']) ? 'da '.$value['min'] : '', isset($value['max']) ? 'a '.$value['max'] : '', isset($value['radius']) ? 'entro '.$value['radius'].' km' : '']));
        }
        return is_numeric($value) ? number_format((float) $value, 0, ',', '.') : (string) $value;
    }
}
