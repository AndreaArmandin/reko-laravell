<?php

namespace App\Gestionale\Actions\Requests;

use App\Gestionale\Audit;
use App\Gestionale\CommandRejected;
use App\Gestionale\Commands;
use App\Gestionale\Questionnaire\Questionnaire;
use App\Models\AgencyMembership;
use App\Models\PropertyRequest;
use Illuminate\Support\Facades\DB;

/**
 * request.step of the gestionale (engine.ts:214-219): saves where the profiling resumes
 * (group id, question id or 'review'). operation must be answered first. Status unchanged.
 */
final class StepPropertyRequest
{
    public function __construct(private readonly Audit $audit) {}

    public function handle(AgencyMembership $actor, PropertyRequest $request, string $stepId, mixed $expectedUpdatedAt): PropertyRequest
    {
        Commands::gate($actor);
        Commands::requireRequest($actor, $request);

        return DB::transaction(function () use ($request, $stepId, $expectedUpdatedAt) {
            $request = PropertyRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();
            Commands::assertRevision($request, $expectedUpdatedAt);

            if (! Questionnaire::hasAnswer($request->criteria['operation'] ?? null) && $stepId !== 'operation') {
                throw new CommandRejected(AnswerPropertyRequestQuestion::OPERATION_FIRST, 400, 'value');
            }
            $before = $request->step_id;
            $request->step_id = mb_substr(Commands::text($stepId) ?: 'review', 0, 100);
            $request->updated_at = now();
            $request->save();
            $this->audit->record('request.step', $request, ['changed' => ['step_id' => [$before, $request->step_id]]]);

            return $request;
        });
    }
}
