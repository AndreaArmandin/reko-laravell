<?php

namespace App\Gestionale\Actions\Requests;

use App\Events\Gestionale\PropertyRequestCompleted;
use App\Gestionale\Audit;
use App\Gestionale\CommandRejected;
use App\Gestionale\Commands;
use App\Gestionale\Questionnaire\Questionnaire;
use App\Models\AgencyMembership;
use App\Models\PropertyRequest;
use Illuminate\Support\Facades\DB;

/**
 * request.finish of the gestionale (engine.ts:214-219): the profile is confirmed. Early statuses
 * become 'Ricerca attiva' (operation + purpose answered) or 'Da completare'; advanced statuses
 * (e.g. 'Visita programmata') stay. step_id = 'review'.
 */
final class FinishPropertyRequest
{
    public function __construct(private readonly Audit $audit) {}

    public function handle(AgencyMembership $actor, PropertyRequest $request, mixed $expectedUpdatedAt): PropertyRequest
    {
        Commands::gate($actor);
        Commands::requireRequest($actor, $request);

        return DB::transaction(function () use ($request, $expectedUpdatedAt) {
            $request = PropertyRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();
            Commands::assertRevision($request, $expectedUpdatedAt);

            $criteria = $request->criteria;
            if (! Questionnaire::hasAnswer($criteria['operation'] ?? null)) {
                throw new CommandRejected(AnswerPropertyRequestQuestion::OPERATION_FIRST, 400, 'value');
            }
            $complete = Questionnaire::hasAnswer($criteria['purpose'] ?? null);
            $before = $request->status;

            $request->finished = true;
            if (in_array($request->status, PropertyRequest::PROFILING_STATUSES, true)) {
                $request->status = $complete ? 'Ricerca attiva' : 'Da completare';
            }
            $request->step_id = 'review';
            $request->updated_at = now();
            $request->save();

            if ($complete) {
                PropertyRequestCompleted::dispatch($request->id);
            }
            $this->audit->record('request.finish', $request, ['changed' => ['status' => [$before, $request->status], 'finished' => [false, true]]]);

            return $request;
        });
    }
}
