<?php

namespace App\Gestionale\Notifications;

use App\Gestionale\Activities\ActivityAccess;
use App\Models\AgencyMembership;
use App\Models\GestionaleNotification;
use Illuminate\Support\Collection;

/** Notifications are exposed only while their linked activity is still visible to the recipient. */
final class NotificationFeed
{
    /** @return Collection<int, GestionaleNotification> */
    public function for(AgencyMembership $actor): Collection
    {
        if (! $actor->isActive()) {
            return collect();
        }

        $notifications = GestionaleNotification::query()
            ->where('agency_id', $actor->agency_id)
            ->where('user_id', $actor->user_id)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->get();

        $visibleActivityIds = $this->visibleActivityIds($actor, $notifications->pluck('activity_id')->filter()->all());

        return $notifications
            ->filter(fn (GestionaleNotification $notification) => $notification->activity_id === null
                || in_array((int) $notification->activity_id, $visibleActivityIds, true))
            ->values();
    }

    public function canSee(AgencyMembership $actor, GestionaleNotification $notification): bool
    {
        if (! $actor->isActive()
            || (int) $notification->agency_id !== (int) $actor->agency_id
            || (int) $notification->user_id !== (int) $actor->user_id) {
            return false;
        }

        return $notification->activity_id === null
            || in_array((int) $notification->activity_id, $this->visibleActivityIds($actor, [(int) $notification->activity_id]), true);
    }

    /** @param list<int|string> $activityIds
     *  @return list<int>
     */
    private function visibleActivityIds(AgencyMembership $actor, array $activityIds): array
    {
        $ids = collect($activityIds)->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        if ($ids === []) {
            return [];
        }

        return app(ActivityAccess::class)
            ->visible($actor, fn ($query) => $query->whereIn('activities.id', $ids))
            ->modelKeys();
    }
}
