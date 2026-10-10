<?php

namespace App\Gestionale\Activities;

use App\Models\GestionaleNotification;

/**
 * workflow.ts notify(): a notice for one user of the current agency, newest first when listed.
 * Written inside the same transaction as the command that causes it.
 */
final class Notifier
{
    public static function notify(int $userId, string $title, ?int $activityId = null, ?int $acquisitionId = null): GestionaleNotification
    {
        $notice = new GestionaleNotification;
        $notice->forceFill([
            'user_id' => $userId,
            'activity_id' => $activityId,
            'acquisition_id' => $acquisitionId,
            'title' => $title,
            'occurred_at' => now(),
            'read_at' => null,
        ])->save();

        return $notice;
    }
}
