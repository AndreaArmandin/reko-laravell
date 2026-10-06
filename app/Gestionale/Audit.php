<?php

namespace App\Gestionale;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes the append-only audit trail (TS state.audit). Every gestionale command records
 * one event in the same transaction as its change. before/after go in the payload.
 */
final class Audit
{
    public function __construct(
        private readonly CurrentAgency $current,
        private readonly AuthFactory $auth,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(string $action, ?Model $subject = null, array $payload = [], ?string $reason = null, ?User $actor = null): AuditEvent
    {
        $agencyId = $this->current->id();
        $subjectAgency = $subject?->getAttribute('agency_id');

        if ($subjectAgency !== null && $agencyId !== null && (int) $subjectAgency !== $agencyId) {
            throw new CrossAgencyWrite('Evento di audit su una scheda di un’altra agenzia.');
        }

        $actor ??= $this->auth->guard()->user();

        $event = new AuditEvent;
        $event->forceFill([
            'agency_id' => $agencyId ?? $subjectAgency,
            'user_id' => $actor?->getKey(),
            'action' => $action,
            'auditable_type' => $subject?->getMorphClass(),
            'auditable_id' => $subject?->getKey(),
            'reason' => $reason !== null && trim($reason) !== '' ? trim($reason) : null,
            'payload' => $payload === [] ? null : $payload,
            'occurred_at' => now(),
        ])->save();

        return $event;
    }
}
