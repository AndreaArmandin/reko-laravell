<?php

namespace App\Gestionale\Goals;

use App\Events\Gestionale\MatchNegotiationStarted;
use App\Events\Gestionale\PropertyPriceRevised;
use App\Events\Gestionale\PropertyPublished;
use App\Events\Gestionale\PropertyRequestCompleted;
use App\Gestionale\Questionnaire\Questionnaire;
use App\Models\Concerns\AgencyScope;
use App\Models\Property;
use App\Models\PropertyMatch;
use App\Models\PropertyRequest;
use Illuminate\Support\Facades\DB;

/**
 * Listeners of the domain events that are objective milestones (engine.ts recordGoalMilestone):
 * completed request, published property, price revision, negotiation started. Each one is recorded once
 * (per request / property / match, the price once per revision) for the operator who did it.
 */
final class GoalMilestones
{
    public function __construct(private readonly GoalEngine $engine) {}

    /** request.finish: 'Cliente profilato', 'Richiesta completata' once purpose and operation are answered. */
    public function requestCompleted(PropertyRequestCompleted $event): void
    {
        $request = PropertyRequest::query()->withoutGlobalScope(AgencyScope::class)->find($event->propertyRequestId);
        if ($request === null) {
            return;
        }
        $criteria = (array) $request->criteria;
        if (! Questionnaire::hasAnswer($criteria['purpose'] ?? null) || ! Questionnaire::hasAnswer($criteria['operation'] ?? null)) {
            return;
        }
        $this->record((int) $request->agency_id, "request:{$request->id}:complete", (int) $request->agent_user_id, ['Cliente profilato', 'Richiesta completata'],
            null, GoalEngine::subjectForContact((int) $request->agency_id, (int) $request->contact_id));
    }

    /** property.save with a published listing link: 'Immobile pubblicato'. */
    public function propertyPublished(PropertyPublished $event): void
    {
        if ($property = $this->property($event->propertyId)) {
            $this->record((int) $property->agency_id, "property:{$property->id}:published", (int) $property->agent_user_id, ['Immobile pubblicato'], (int) $property->id);
        }
    }

    /** A new price: 'Revisione di prezzo ottenuta', worth the new price for amount goals. */
    public function priceRevised(PropertyPriceRevised $event): void
    {
        if ($property = $this->property($event->propertyId)) {
            $this->record((int) $property->agency_id, "price:{$property->id}:".hrtime(true), (int) $property->agent_user_id, ['Revisione di prezzo ottenuta'], (int) $property->id, '', $event->price);
        }
    }

    /** match.state 'In trattativa': 'Trattativa avviata' on the property, for the client. */
    public function negotiationStarted(MatchNegotiationStarted $event): void
    {
        $match = PropertyMatch::query()->withoutGlobalScope(AgencyScope::class)->find($event->propertyMatchId);
        $request = $match ? PropertyRequest::query()->withoutGlobalScope(AgencyScope::class)->find($match->property_request_id) : null;
        if ($match === null || $request === null) {
            return;
        }
        $this->record((int) $match->agency_id, "match:{$match->id}:negotiation", (int) $request->agent_user_id, ['Trattativa avviata'], (int) $match->property_id,
            GoalEngine::subjectForContact((int) $match->agency_id, (int) $request->contact_id));
    }

    private function property(int $id): ?Property
    {
        return Property::query()->withoutGlobalScope(AgencyScope::class)->find($id);
    }

    private function record(int $agencyId, string $key, int $fallbackUserId, array $types, ?int $propertyId = null, string $subject = '', float $amount = 0): void
    {
        $operator = self::operator($agencyId, $fallbackUserId);
        if ($operator !== null) {
            $this->engine->recordMilestone($agencyId, $key, $operator, $types, null, $propertyId, $subject, $amount);
        }
    }

    /** The person who did it (the logged-in member of the agency), else the referent of the record. */
    public static function operator(int $agencyId, int $fallbackUserId): ?int
    {
        foreach ([auth()->id(), $fallbackUserId] as $userId) {
            if ($userId && DB::table('agency_memberships')->where('agency_id', $agencyId)->where('user_id', $userId)->whereNull('deactivated_at')->exists()) {
                return (int) $userId;
            }
        }

        return null;
    }
}
