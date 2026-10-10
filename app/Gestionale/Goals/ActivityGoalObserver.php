<?php

namespace App\Gestionale\Goals;

use App\Models\Activity;
use Illuminate\Support\Facades\DB;

/**
 * Keeps goal_events and goal_ledger in step with the agenda (the original recomputes them after every
 * mutation). Reacts to the model events only, so it does not depend on the Agenda screen. The work runs
 * after the surrounding transaction commits; an already completed activity that is changed again is a
 * correction, which also recomputes the closed periods it counted in.
 */
final class ActivityGoalObserver
{
    public function __construct(private readonly GoalEngine $engine) {}

    public function saved(Activity $activity): void
    {
        $corrected = $activity->wasChanged()
            && ($activity->getOriginal('completed_at') !== null || $activity->getOriginal('status') === 'Completata');
        DB::afterCommit(function () use ($activity, $corrected) {
            try {
                $this->engine->activityChanged($activity, $corrected);
            } catch (\Throwable $e) {
                report($e);
            }
        });
    }

    public function deleted(Activity $activity): void
    {
        $agencyId = (int) $activity->agency_id;
        $id = (int) $activity->id;
        DB::afterCommit(function () use ($agencyId, $id) {
            try {
                $this->engine->activityRemoved($agencyId, $id);
            } catch (\Throwable $e) {
                report($e);
            }
        });
    }
}
