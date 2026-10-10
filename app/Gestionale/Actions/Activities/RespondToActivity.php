<?php

namespace App\Gestionale\Actions\Activities;

use App\Gestionale\Activities\ActivityAccess;
use App\Gestionale\Activities\ActivityCatalog;
use App\Gestionale\Activities\Notifier;
use App\Gestionale\Audit;
use App\Gestionale\CommandRejected;
use App\Gestionale\Commands;
use App\Models\Activity;
use App\Models\ActivityEvent;
use App\Models\AgencyMembership;
use Illuminate\Support\Facades\DB;

/**
 * activity.respond / activity.done of the gestionale (workflow.ts respondActivity): the assignee
 * (or an administrator) answers an activity with Accetta, Rinvia, Chiedi chiarimenti or Annulla,
 * or completes it (Completa). Each answer is kept in the history (activity_events, kind "response")
 * with the previous status and due date, and the creator is notified.
 */
final class RespondToActivity
{
    public function __construct(
        private readonly ActivityAccess $access,
        private readonly SaveActivity $save,
        private readonly Audit $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data  reason, due_at (Rinvia); outcome, notes… for Completa (as SaveActivity)
     */
    public function handle(AgencyMembership $actor, Activity $activity, string $response, array $data = []): Activity
    {
        return DB::transaction(function () use ($actor, $activity, $response, $data) {
            $old = Activity::query()->where('agency_id', $actor->agency_id)->lockForUpdate()->find($activity->id);
            if (! $old || ! $this->access->canSee($actor, $old) || ! $this->access->canComplete($actor, $old)) {
                throw new CommandRejected('Solo il destinatario può gestire l’avanzamento.', 403);
            }
            if (ActivityCatalog::suspendedAcquisition($old)) {
                throw new CommandRejected(SaveActivity::SUSPENDED, 410);
            }
            if ($old->isDone() || $old->outcome_confirmed_at !== null) {
                throw new CommandRejected('L’attività è confermata. Le correzioni richiedono l’Amministratore e una motivazione.', 403);
            }

            if ($response === 'Completa') {
                return $this->save->handle($actor, $old, array_merge($data, [
                    'done' => true,
                    'outcome' => $data['outcome'] ?? $old->outcome,
                ]));
            }
            if (! in_array($response, ActivityCatalog::RESPONSES, true)) {
                throw new CommandRejected('Azione non valida.');
            }

            $previousStatus = $old->status ?: 'Da svolgere';
            $previousDueAt = $old->scheduled_at;
            $reason = Commands::text($data['reason'] ?? '');
            $now = now();

            if ($response === 'Rinvia') {
                $due = SaveActivity::parseDate($data['due_at'] ?? null);
                if ($due === null || $due->lte($now)) {
                    throw new CommandRejected('Scegli una nuova data futura.');
                }
                $old->forceFill(['scheduled_at' => $due, 'status' => 'Rinviata']);
            } elseif ($response === 'Annulla') {
                if ($reason === '') {
                    throw new CommandRejected('Indica il motivo dell’annullamento.');
                }
                $old->forceFill(['status' => 'Annullata', 'cancelled_at' => $now]);
            } elseif ($response === 'Chiedi chiarimenti') {
                if ($reason === '') {
                    throw new CommandRejected('Scrivi il chiarimento richiesto.');
                }
                $old->forceFill(['status' => 'Chiarimenti richiesti']);
            } else {
                $old->forceFill(['status' => 'Accettata']);
            }
            $old->updated_at = $now;
            $old->save();

            ActivityEvent::query()->create([
                'activity_id' => $old->id, 'user_id' => $actor->user_id, 'kind' => 'response', 'occurred_at' => $now,
                'payload' => ['action' => $response, 'reason' => $reason, 'previous_status' => $previousStatus,
                    'previous_due_at' => $previousDueAt?->toIso8601String()],
            ]);
            if ($old->created_by_user_id && (int) $old->created_by_user_id !== (int) $actor->user_id) {
                Notifier::notify((int) $old->created_by_user_id, $response.' · attività assegnata', $old->id);
            }
            $this->audit->record('activity.respond', $old, ['response' => $response, 'before' => $previousStatus, 'after' => $old->status], $reason ?: null);

            return $old->refresh();
        });
    }

    /** activity.done with done=false: reopening is reserved to the administrator. */
    public function reopen(AgencyMembership $actor, Activity $activity, string $reason): Activity
    {
        if ($actor->role !== 'admin') {
            throw new CommandRejected('Riapertura riservata all’Amministratore.', 403);
        }

        return $this->save->handle($actor, $activity, ['done' => false, 'reason' => $reason]);
    }
}
