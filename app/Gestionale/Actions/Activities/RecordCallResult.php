<?php

namespace App\Gestionale\Actions\Activities;

use App\Gestionale\Activities\ActivityAccess;
use App\Gestionale\Activities\ActivityCatalog;
use App\Gestionale\CommandRejected;
use App\Gestionale\Idempotency;
use App\Gestionale\IdempotencyConflict;
use App\Models\Activity;
use App\Models\AgencyMembership;
use Illuminate\Support\Facades\DB;

/**
 * activity.call-result of the gestionale (call-result.ts recordCallResult): the quick outcome of a
 * phone call. One of four results; "Richiama il…" also creates the follow-up call, with the same
 * links and the same assignee, chained to the first (next/previous). The whole thing is one
 * transaction and runs once per token: an identical retry returns the first result, the same token
 * with different data is refused (409).
 */
final class RecordCallResult
{
    public function __construct(
        private readonly ActivityAccess $access,
        private readonly SaveActivity $save,
        private readonly Idempotency $idempotency,
    ) {}

    /**
     * @param  array{token: string, result: string, note?: ?string, next_at?: mixed}  $input
     * @return array{completed: Activity, next: ?Activity}
     */
    public function handle(AgencyMembership $actor, Activity $activity, array $input): array
    {
        $this->permittedCall($actor, $activity);
        $data = $this->validated($input);

        try {
            $response = $this->idempotency->run('activity.call-result', $data['token'], [
                'id' => $activity->id, 'result' => $data['option']['id'], 'note' => $data['note'], 'next_at' => $data['next_at']?->toIso8601String(),
            ], fn () => $this->record($actor, $activity, $data));
        } catch (IdempotencyConflict) {
            throw new CommandRejected('Questo identificativo è già stato usato per un riscontro diverso.', 409);
        }

        return [
            'completed' => Activity::query()->findOrFail($response['completed_id']),
            'next' => ($response['next_id'] ?? null) ? Activity::query()->find($response['next_id']) : null,
        ];
    }

    private function permittedCall(AgencyMembership $actor, Activity $activity): void
    {
        if (! $this->access->canSee($actor, $activity) || ! $this->access->canComplete($actor, $activity)) {
            throw new CommandRejected('Solo il destinatario o l’Amministratore può registrare questo riscontro.', 403);
        }
        if ($activity->kind !== 'Telefonata') {
            throw new CommandRejected('Il riscontro rapido riguarda solo le telefonate.');
        }
        if (ActivityCatalog::suspendedAcquisition($activity)) {
            throw new CommandRejected('Il percorso incarichi è sospeso. I dati storici restano conservati.', 410);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{token: string, option: array<string, mixed>, note: string, next_at: ?\Illuminate\Support\Carbon}
     */
    private function validated(array $input): array
    {
        if (array_diff(array_keys($input), ['token', 'result', 'note', 'next_at']) !== []) {
            throw new CommandRejected('Dati del riscontro non validi.');
        }
        $token = $input['token'] ?? null;
        if (! is_string($token) || preg_match('/^[a-zA-Z0-9_-]{16,100}$/', $token) !== 1) {
            throw new CommandRejected('Identificativo del riscontro non valido.');
        }
        $option = collect(ActivityCatalog::CALL_RESULTS)->firstWhere('id', $input['result'] ?? null);
        if (! $option) {
            throw new CommandRejected('Scegli uno dei quattro esiti della telefonata.');
        }
        if (isset($input['note']) && ! is_string($input['note'])) {
            throw new CommandRejected('La nota deve essere un testo.');
        }
        $note = trim((string) ($input['note'] ?? ''));
        if (mb_strlen($note) > 4000) {
            throw new CommandRejected('La nota può contenere al massimo 4.000 caratteri.');
        }
        $nextAt = null;
        if ($option['id'] === 'callback') {
            $nextAt = SaveActivity::parseDate($input['next_at'] ?? null)
                ?? throw new CommandRejected('Indica data e ora del richiamo.');
        } elseif (isset($input['next_at']) && $input['next_at'] !== '') {
            throw new CommandRejected('La data del richiamo è prevista solo per “Richiama il…”.');
        }

        return ['token' => $token, 'option' => $option, 'note' => $note, 'next_at' => $nextAt];
    }

    /** @return array{completed_id: int, next_id: ?int} */
    private function record(AgencyMembership $actor, Activity $activity, array $data): array
    {
        return DB::transaction(function () use ($actor, $activity, $data) {
            $old = Activity::query()->where('agency_id', $actor->agency_id)->lockForUpdate()->findOrFail($activity->id);
            $old->load(['units', 'participants']);
            if ($old->isDone() || $old->outcome_confirmed_at !== null || $old->status === 'Annullata') {
                throw new CommandRejected('La telefonata non è più da completare. Per correggerla usa il percorso con motivazione.', 409);
            }
            $option = $data['option'];
            if ($option['id'] === 'callback') {
                if ($data['next_at']->lte(now())) {
                    throw new CommandRejected('Scegli una data futura per il richiamo.');
                }
                if ($old->next_activity_id) {
                    throw new CommandRejected('Questa telefonata ha già un’attività successiva collegata. Gestiscila dall’agenda, senza crearne un’altra.', 409);
                }
            }
            $note = implode("\n\n", array_filter([(string) $old->notes, $data['note']], fn ($part) => $part !== ''));
            if (mb_strlen($note) > 4000) {
                throw new CommandRejected('La nota precedente e la nuova nota superano 4.000 caratteri: abbrevia solo la nuova nota. Lo storico non viene troncato.');
            }

            $completed = $this->save->handle($actor, $old, [
                'done' => true, 'outcome' => $option['outcome'], 'answered' => $option['answered'], 'notes' => $note,
            ]);

            $next = null;
            if ($option['id'] === 'callback') {
                $next = $this->save->handle($actor, null, [
                    'type' => 'Telefonata', 'title' => mb_substr('Richiamo · '.$old->subject, 0, 180), 'due_at' => $data['next_at'],
                    'notes' => '', 'done' => false, 'outcome' => '', 'answered' => false, 'amount' => 0, 'event_type' => '',
                    'assigned_to_user_id' => $old->assigned_to_user_id, 'shared_with' => $old->participants->pluck('user_id')->filter()->all(),
                    'priority' => $old->priority ?: 'Normale', 'internal' => $old->internal,
                    'contact_id' => $old->contact_id, 'property_request_id' => $old->property_request_id, 'property_id' => $old->property_id,
                    'owner_contact_id' => $old->owner_contact_id, 'parcel_id' => $old->parcel_id,
                    'unit_ids' => $old->units->pluck('cadastral_unit_id')->all(), 'interest' => $old->interest,
                ], $completed);
                $next->forceFill(['previous_activity_id' => $completed->id])->save();
                $completed->forceFill(['next_activity_id' => $next->id])->save();
            }

            return ['completed_id' => $completed->id, 'next_id' => $next?->id];
        });
    }
}
