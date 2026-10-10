<?php

namespace App\Events\Gestionale;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A property is published with a listing link (engine.ts recordGoalMilestone 'Immobile pubblicato').
 * No listener until the Objectives phase.
 */
final class PropertyPublished implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly int $propertyId) {}
}
