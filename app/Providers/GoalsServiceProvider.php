<?php

namespace App\Providers;

use App\Events\Gestionale\MatchNegotiationStarted;
use App\Events\Gestionale\PropertyPriceRevised;
use App\Events\Gestionale\PropertyPublished;
use App\Events\Gestionale\PropertyRequestCompleted;
use App\Gestionale\Goals\ActivityGoalObserver;
use App\Gestionale\Goals\GoalMilestones;
use App\Models\Activity;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the objectives engine: agenda model events and the domain events that are milestones.
 */
class GoalsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Activity::observe(ActivityGoalObserver::class);

        Event::listen(PropertyRequestCompleted::class, [GoalMilestones::class, 'requestCompleted']);
        Event::listen(PropertyPublished::class, [GoalMilestones::class, 'propertyPublished']);
        Event::listen(PropertyPriceRevised::class, [GoalMilestones::class, 'priceRevised']);
        Event::listen(MatchNegotiationStarted::class, [GoalMilestones::class, 'negotiationStarted']);
    }
}
