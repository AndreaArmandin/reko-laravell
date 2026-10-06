<?php

namespace App\Gestionale\Actions\Requests;

use App\Events\Gestionale\PropertyRequestCriteriaChanged;
use App\Gestionale\Audit;
use App\Gestionale\CommandRejected;
use App\Gestionale\Commands;
use App\Gestionale\Questionnaire\AnswerValidator;
use App\Gestionale\Questionnaire\Questionnaire;
use App\Models\AgencyMembership;
use App\Models\PropertyRequest;
use Illuminate\Support\Facades\DB;

/**
 * request.answer of the gestionale (engine.ts:193-213): one questionnaire answer.
 * operation first; only questions pertinent to the current path; changing the contract drops
 * budget and fees, changing the purpose resets the typology (except a compatible quick typology on
 * the first purpose answer); stale answers are cleaned (archived ones kept); automatic title;
 * 'Nuova' becomes 'Da completare'.
 */
final class AnswerPropertyRequestQuestion
{
    public const OPERATION_FIRST = 'Scegli prima Acquisto o Locazione: questa risposta è obbligatoria.';

    public function __construct(private readonly Audit $audit) {}

    /**
     * @param  array<string, mixed>  $input  question_id, value, classification?, next_step?, expected_updated_at
     */
    public function handle(AgencyMembership $actor, PropertyRequest $request, array $input): PropertyRequest
    {
        Commands::gate($actor);
        Commands::requireRequest($actor, $request);

        return DB::transaction(function () use ($actor, $request, $input) {
            $request = PropertyRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();
            Commands::assertRevision($request, $input['expected_updated_at'] ?? null);

            $questionnaire = Questionnaire::forAgency((int) $actor->agency_id);
            $criteria = $request->criteria;
            $key = Commands::text($input['question_id'] ?? '');
            $value = $input['value'] ?? null;
            $question = array_column($questionnaire->visible($criteria), null, 'id')[$key] ?? null;

            if ($key !== 'operation' && ! Questionnaire::hasAnswer($criteria['operation'] ?? null)) {
                throw new CommandRejected(self::OPERATION_FIRST, 400, 'value');
            }
            if ($question === null) {
                throw new CommandRejected('Questa domanda non è pertinente al percorso attuale.', 400, 'value');
            }
            $value = AnswerValidator::validate($value, $question);
            $classification = $input['classification'] ?? null;
            if ($classification && ! in_array($classification, Questionnaire::CLASSIFICATIONS, true)) {
                throw new CommandRejected('Classificazione non valida.', 400, 'classification');
            }

            $before = $criteria[$key] ?? null;
            if ($key === 'operation' && ($criteria['operation'] ?? null) !== $value) {
                unset($criteria['budget'], $criteria['fees']);
            }
            if ($key === 'purpose' && ($criteria['purpose'] ?? null) !== $value) {
                $choices = Questionnaire::typologies([...$criteria, 'purpose' => $value]);
                $previous = $criteria['typology'] ?? null;
                // Keep a compatible quick typology on the FIRST purpose answer only.
                if (Questionnaire::hasAnswer($criteria['purpose'] ?? null) || ! is_array($previous) || array_diff($previous, $choices) !== []) {
                    unset($criteria['typology']);
                }
            }
            $criteria = $questionnaire->clean([...$criteria, $key => $value]);

            $request->criteria = $criteria;
            if ($request->title_auto && in_array($key, ['operation', 'purpose', 'typology', 'zones'], true)) {
                $request->title = Questionnaire::autoTitle($criteria);
            }
            if ($classification) {
                $request->classifications = [...$request->classifications, $key => $classification];
            }
            $request->step_id = mb_substr(Commands::text(($input['next_step'] ?? '') ?: $key), 0, 100);
            if ($request->status === 'Nuova') {
                $request->status = 'Da completare';
            }
            $request->updated_at = now();
            $request->save();

            app(\App\Gestionale\Properties\PropertyMatcher::class)->refreshRequest($request);

            PropertyRequestCriteriaChanged::dispatch($request->id);
            $this->audit->record('request.answer', $request, ['question_id' => $key, 'changed' => [$key => [$before, $criteria[$key] ?? null]]]);

            return $request;
        });
    }
}
