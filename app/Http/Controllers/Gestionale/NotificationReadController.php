<?php

namespace App\Http\Controllers\Gestionale;

use App\Gestionale\Actions\Notifications\MarkNotificationRead;
use App\Gestionale\CurrentAgency;
use App\Http\Controllers\Controller;
use App\Models\GestionaleNotification;
use Illuminate\Http\RedirectResponse;

/**
 * Bell of the topbar: opening a notice marks it "Letta" (notification.read) and, when it points to an
 * activity, opens the agenda on it (go('Attività', activityId) of the old gestionale).
 */
class NotificationReadController extends Controller
{
    public function __invoke(int $notification, CurrentAgency $current, MarkNotificationRead $action): RedirectResponse
    {
        $actor = $current->membership() ?? abort(403, 'Nessuna agenzia attiva per questo account.');
        $notice = GestionaleNotification::query()->find($notification);
        abort_if($notice === null, 403, 'Notifica non accessibile.');

        $action->handle($actor, $notice);

        return $notice->activity_id && in_array('Attività', \App\Gestionale\Navigation::sectionsFor($actor), true)
            ? redirect()->route('gestionale.activities.index', ['id' => $notice->activity_id])
            : redirect()->back();
    }
}
