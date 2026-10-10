<?php

namespace App\Gestionale\Actions\Activities;

use App\Gestionale\CommandRejected;
use App\Models\AgencyMembership;
use App\Models\GestionaleNotification;

/**
 * notification.read of the gestionale (engine.ts): a person marks one of THEIR notices as read.
 * A notice of someone else (or of another agency) is "Notifica non accessibile." (403).
 * Used by the bell in the topbar: GestionaleNotification::query()->where('user_id', …)->whereNull('read_at').
 */
final class MarkNotificationRead
{
    public function handle(AgencyMembership $actor, int|GestionaleNotification $notification): GestionaleNotification
    {
        $id = $notification instanceof GestionaleNotification ? $notification->getKey() : $notification;
        $notice = GestionaleNotification::query()->where('agency_id', $actor->agency_id)
            ->where('user_id', $actor->user_id)->find($id);
        if (! $actor->isActive() || $notice === null) {
            throw new CommandRejected('Notifica non accessibile.', 403);
        }
        if ($notice->read_at === null) {
            $notice->forceFill(['read_at' => now()])->save();
        }

        return $notice;
    }
}
