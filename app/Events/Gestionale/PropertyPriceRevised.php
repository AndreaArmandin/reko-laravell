<?php

namespace App\Events\Gestionale;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The price of a property changed (engine.ts recordGoalMilestone 'Revisione di prezzo ottenuta').
 * No listener until the Objectives phase.
 */
final class PropertyPriceRevised implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly int $propertyId, public readonly float $price) {}
}
