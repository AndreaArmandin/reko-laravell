<?php

namespace App\Events\Gestionale;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A request was completed with operation and purpose (engine.ts recordGoalMilestone 'Cliente profilato', 'Richiesta completata'). No listener until the Objectives phase.
 */
final class PropertyRequestCompleted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly int $propertyRequestId) {}
}
