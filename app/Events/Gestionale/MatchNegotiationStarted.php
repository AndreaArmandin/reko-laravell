<?php

namespace App\Events\Gestionale;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A match moved to "In trattativa" (engine.ts match.state, recordGoalMilestone 'Trattativa avviata').
 * The Objectives phase listens to it; the match workflow itself needs no listener.
 */
final class MatchNegotiationStarted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly int $propertyMatchId) {}
}
