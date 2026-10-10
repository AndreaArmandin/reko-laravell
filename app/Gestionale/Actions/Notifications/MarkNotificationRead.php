<?php

namespace App\Gestionale\Actions\Notifications;

use App\Gestionale\Audit;
use App\Gestionale\CommandRejected;
use App\Gestionale\Notifications\NotificationFeed;
use App\Models\AgencyMembership;
use App\Models\GestionaleNotification;
use Illuminate\Support\Facades\DB;

/**
 * notification.read (engine.ts): only the recipient marks a notice as read ("Letta"); someone
 * else's notice is "Notifica non accessibile." (403). Notices are written by the workflow
 * (App\Gestionale\Activities\Notifier).
 */
final class MarkNotificationRead
{
    public function __construct(private readonly Audit $audit, private readonly NotificationFeed $feed) {}

    public function handle(AgencyMembership $actor, GestionaleNotification $notification): GestionaleNotification
    {
        if (! $actor->isActive() || (int) $notification->agency_id !== (int) $actor->agency_id
            || (int) $notification->user_id !== (int) $actor->user_id
            || ! $this->feed->canSee($actor, $notification)) {
            throw new CommandRejected('Notifica non accessibile.', 403);
        }

        return DB::transaction(function () use ($notification) {
            $notification->read_at ??= now();
            $notification->save();
            $this->audit->record('notification.read', $notification);

            return $notification;
        });
    }
}
