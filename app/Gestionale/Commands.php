<?php

namespace App\Gestionale;

use App\Models\AgencyMembership;
use App\Models\Contact;
use App\Models\PropertyRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Rules shared by the client.* and request.* commands (engine.ts applyAction, requireRecord, text).
 */
final class Commands
{
    public const SCOUT_DENIED = 'Operazione non consentita all’Operatore 1.';

    public const RECORD_DENIED = 'Record non accessibile con questo ruolo.';

    public const STALE = 'I dati sono cambiati. Aggiorna la vista e riprova.';

    public const MISSING_REVISION = 'Ricarica la scheda prima di salvare.';

    public const REVISION_FORMAT = 'Y-m-d H:i:s.u';

    /** Role gate (engine.ts:111): an inactive member or a scout cannot run client.* / request.*. */
    public static function gate(AgencyMembership $actor): void
    {
        if (! $actor->isActive() || ! in_array($actor->role, ['admin', 'crm'], true)) {
            throw new CommandRejected(self::SCOUT_DENIED, 403);
        }
    }

    /** requireRecord(): admin any record of its agency, crm only the ones it is the referent of. */
    public static function requireClient(AgencyMembership $actor, ?Contact $client): Contact
    {
        $profile = $client?->clientProfile;
        if ($profile === null || ! self::canManage($actor, (int) $client->agency_id, (int) $profile->agent_user_id)) {
            throw new CommandRejected(self::RECORD_DENIED, 403);
        }

        return $client;
    }

    public static function requireRequest(AgencyMembership $actor, ?PropertyRequest $request): PropertyRequest
    {
        if ($request === null || ! self::canManage($actor, (int) $request->agency_id, (int) $request->agent_user_id)) {
            throw new CommandRejected(self::RECORD_DENIED, 403);
        }

        return $request;
    }

    public static function canManage(AgencyMembership $actor, int $agencyId, int $agentUserId): bool
    {
        return $actor->isActive() && (int) $actor->agency_id === $agencyId
            && ($actor->role === 'admin' || $agentUserId === (int) $actor->user_id);
    }

    /**
     * Optimistic revision per record (TS expectedRevision): missing → 428, different → 409.
     * Call after lockForUpdate so two concurrent edits cannot both pass.
     */
    public static function assertRevision(Model $record, mixed $expected): void
    {
        if ($expected === null || $expected === '') {
            throw new CommandRejected(self::MISSING_REVISION, 428);
        }
        try {
            $expected = Carbon::parse((string) $expected)->format(self::REVISION_FORMAT);
        } catch (\Throwable) {
            throw new CommandRejected(self::STALE, 409);
        }
        if ($record->updated_at?->format(self::REVISION_FORMAT) !== $expected) {
            throw new CommandRejected(self::STALE, 409);
        }
    }

    public static function revision(?Model $record): ?string
    {
        return $record?->updated_at?->format(self::REVISION_FORMAT);
    }

    /** engine.ts text(): trim and silently truncate. */
    public static function text(mixed $value, int $max = 4000): string
    {
        return mb_substr(trim((string) ($value ?? '')), 0, $max);
    }
}
