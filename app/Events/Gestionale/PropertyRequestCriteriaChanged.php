<?php

namespace App\Events\Gestionale;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The criteria, client or referent of a request changed (engine.ts changedMatching). No listener until the Matching phase recomputes the matches.
 */
final class PropertyRequestCriteriaChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly int $propertyRequestId) {}
}
