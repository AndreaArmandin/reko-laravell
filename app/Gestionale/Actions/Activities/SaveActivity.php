<?php

namespace App\Gestionale\Actions\Activities;

use App\Gestionale\Activities\ActivityAccess;
use App\Gestionale\Activities\ActivityCatalog;
use App\Gestionale\Activities\Notifier;
use App\Gestionale\Audit;
use App\Gestionale\CommandRejected;
use App\Gestionale\Commands;
use App\Gestionale\Idempotency;
use App\Models\Activity;
use App\Models\ActivityEvent;
use App\Models\AgencyMembership;
use App\Models\CadastralUnit;
use App\Models\Ownership;
use App\Models\PropertyRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * activity.save of the gestionale (workflow.ts saveActivity + engine.ts activity.save): create or
 * edit an activity, with the same checks, messages and statuses. Also the only code that notifies
 * about new assignments, shares and updates. One transaction: activity, links, participants,
 * the optional linked reminder, notices, audit.
 *
 * Input keys (all optional on edit, missing keys keep the stored value):
 *   type, title, notes, due_at, done, outcome, priority, assigned_to_user_id, shared_with[user ids],
 *   internal, contact_id (client), property_request_id, property_id, owner_contact_id, parcel_id,
 *   unit_ids[], interest, event_type, amount, contact_operation, crm_operation, crm_category,
 *   answered, metadata[], reason (correction of a confirmed activity), recorded (the "Registra attività"
 *   form), reminder{title, due_at} (new recorded activity only), expected_revision, idempotency_key.
 */
final class SaveActivity
{
    public const SUSPENDED = 'Il percorso incarichi è sospeso. I dati storici restano conservati.';

    public function __construct(
        private readonly ActivityAccess $access,
        private readonly Audit $audit,
        private readonly Idempotency $idempotency,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(AgencyMembership $actor, ?Activity $old, array $input, ?Activity $followupOf = null): Activity
    {
        $this->assertActor($actor);

        $run = function () use ($actor, $old, $input, $followupOf): Activity {
            $reminder = $this->reminderInput($old, $input);
            $activity = $this->save($actor, $old, $input, $followupOf);

            if ($reminder !== null) {
                // The linked reminder inherits the saved, authorized context, never a second selection.
                $next = $this->save($actor, null, [
                    'type' => 'Promemoria', 'title' => $reminder['title'], 'notes' => '', 'due_at' => $reminder['due_at'],
                    'assigned_to_user_id' => $activity->assigned_to_user_id, 'internal' => $activity->internal,
                    'shared_with' => $activity->participants->pluck('user_id')->filter()->all(),
                    'contact_id' => $activity->contact_id, 'property_request_id' => $activity->property_request_id,
                    'property_id' => $activity->property_id, 'owner_contact_id' => $activity->owner_contact_id,
                    'parcel_id' => $activity->parcel_id, 'unit_ids' => $activity->units->pluck('cadastral_unit_id')->all(),
                ], $activity);
                $activity->forceFill(['next_activity_id' => $next->id])->save();
                $next->forceFill(['previous_activity_id' => $activity->id])->save();
            }

            return $activity->refresh();
        };

        $key = $input['idempotency_key'] ?? null;
        if (is_string($key) && $key !== '' && $old === null) {
            $response = $this->idempotency->run('activity.save', $key, array_diff_key($input, ['idempotency_key' => true]),
                fn () => ['activity_id' => $run()->id]);

            return Activity::query()->findOrFail($response['activity_id']);
        }

        return DB::transaction($run);
    }

    private function assertActor(AgencyMembership $actor): void
    {
        if (! $actor->isActive()) {
            throw new CommandRejected('Sessione non valida.', 401);
        }
    }

    /**
     * engine.ts activity.save: recorded/reminder preconditions.
     *
     * @return array{title: string, due_at: Carbon}|null
     */
    private function reminderInput(?Activity $old, array $input): ?array
    {
        if (($input['recorded'] ?? false) === true
            && (($input['type'] ?? null) === 'Promemoria' || ($input['done'] ?? null) !== true
                || Commands::text($input['outcome'] ?? '') === '' || Commands::text($input['notes'] ?? '') === '')) {
            throw new CommandRejected('Per registrare un’attività completa tipo, esito e commento.');
        }
        if (! array_key_exists('reminder', $input) || $input['reminder'] === null) {
            return null;
        }
        if ($old !== null || ($input['recorded'] ?? false) !== true) {
            throw new CommandRejected('Il promemoria collegato si crea insieme a una nuova attività.');
        }
        if (! is_array($input['reminder'])) {
            throw new CommandRejected('Promemoria non valido.');
        }
        $title = Commands::text($input['reminder']['title'] ?? '', 180);
        $due = self::parseDate($input['reminder']['due_at'] ?? null);
        if ($title === '' || $due === null || $due->lte(now())) {
            throw new CommandRejected('Per il promemoria indica titolo, data e ora future.');
        }

        return ['title' => $title, 'due_at' => $due];
    }

    /** @param array<string, mixed> $input */
    private function save(AgencyMembership $actor, ?Activity $old, array $input, ?Activity $followupOf): Activity
    {
        $now = now();
        $role = $actor->role;
        $access = $this->access;

        if ($old !== null) {
            $old = Activity::query()->where('agency_id', $actor->agency_id)->lockForUpdate()->find($old->id)
                ?? throw new CommandRejected('Attività non modificabile con questo ruolo.', 403);
            $old->load(['units', 'participants']);
            if (! $access->canSee($actor, $old) || ! $access->canEdit($actor, $old)) {
                throw new CommandRejected('Attività non modificabile con questo ruolo.', 403);
            }
            if (array_key_exists('expected_revision', $input) && $input['expected_revision'] !== null) {
                Commands::assertRevision($old, $input['expected_revision']);
            }
            if (($old->isDone() || $old->outcome_confirmed_at !== null) && $role === 'admin' && Commands::text($input['reason'] ?? '') === '') {
                throw new CommandRejected('Indica il motivo della correzione. L’esito precedente resterà nel registro.');
            }
        }

        $has = fn (string $key) => array_key_exists($key, $input);
        $pick = fn (string $key, mixed $stored) => $has($key) ? $input[$key] : $stored;
        $oldUnits = $old ? $old->units->pluck('cadastral_unit_id')->map(fn ($id) => (int) $id)->all() : [];
        $oldShared = $old ? $old->participants->pluck('user_id')->filter()->map(fn ($id) => (int) $id)->all() : [];

        $type = $pick('type', $old?->kind);
        $title = Commands::text($pick('title', $old?->subject), 180);
        $agentId = (int) ($pick('assigned_to_user_id', $old?->assigned_to_user_id) ?: $actor->user_id);
        $contactId = $this->id($pick('contact_id', $old?->contact_id));
        $requestId = $this->id($pick('property_request_id', $old?->property_request_id));
        $propertyId = $this->id($pick('property_id', $old?->property_id));
        $ownerId = $this->id($pick('owner_contact_id', $old?->owner_contact_id));
        $parcelId = $this->id($pick('parcel_id', $old?->parcel_id));
        $units = $has('unit_ids') ? $input['unit_ids'] : $oldUnits;
        $sharedWith = $has('shared_with') ? $input['shared_with'] : $oldShared;
        $priority = $pick('priority', $old?->priority) ?: 'Normale';
        $amount = $pick('amount', $old?->amount);
        $done = $has('done') ? $input['done'] === true : (bool) $old?->isDone();
        $contactOperation = $pick('contact_operation', $old?->contact_operation);
        $crmOperation = $pick('crm_operation', $old?->crm_operation);
        $crmCategory = $pick('crm_category', $old?->crm_category);
        $due = $has('due_at') && $input['due_at'] !== null && $input['due_at'] !== '' ? self::parseDate($input['due_at']) : ($old?->scheduled_at ?? $now);

        // engine.ts: the acquisition path is suspended, its history stays read-only.
        if (($old && ActivityCatalog::suspendedAcquisition($old))
            || ActivityCatalog::suspendedAcquisition(['type' => $type, 'outcome' => $pick('outcome', $old?->outcome)])) {
            throw new CommandRejected(self::SUSPENDED, 410);
        }

        if ($role === 'scout') {
            // Only the verified callback path can inherit a historical link.
            $parent = ! $old && $followupOf && $followupOf->isDone() && $access->canSee($actor, $followupOf) && $access->canComplete($actor, $followupOf) ? $followupOf : null;
            $preserved = $old?->property_id ?? $parent?->property_id;
            if ($propertyId && $propertyId !== (int) $preserved) {
                throw new CommandRejected('Il portafoglio non è accessibile con questo profilo.', 403);
            }
            $propertyId = $preserved ? (int) $preserved : null;
        }

        $assignee = AgencyMembership::query()->active()->where('agency_id', $actor->agency_id)->where('user_id', $agentId)->first();
        if (! $assignee) {
            throw new CommandRejected('Scegli un destinatario attivo.');
        }
        if ($agentId !== (int) $actor->user_id && ! $old && ! $actor->allows('activities.assign')
            || $old && $agentId !== (int) $old->assigned_to_user_id && $role !== 'admin') {
            throw new CommandRejected('Assegnazione non consentita.', 403);
        }

        $sharedWith = is_array($sharedWith) ? array_values(array_unique(array_map('intval', $sharedWith))) : null;
        $sharedMembers = $sharedWith === null ? collect() : AgencyMembership::query()->active()->where('agency_id', $actor->agency_id)->whereIn('user_id', $sharedWith)->get();
        if ($sharedWith === null || $sharedMembers->count() !== count($sharedWith) || ($sharedWith !== [] && ! $actor->allows('activities.share'))) {
            throw new CommandRejected('Condivisione non consentita.', 403);
        }

        if ($requestId) {
            $request = PropertyRequest::query()->where('agency_id', $actor->agency_id)->find($requestId)
                ?? throw new CommandRejected('Richiesta non accessibile.', 403);
            if ($contactId && $contactId !== (int) $request->contact_id) {
                throw new CommandRejected('La richiesta non appartiene al cliente.');
            }
            $contactId = (int) $request->contact_id;
        }

        $units = is_array($units) ? $units : null;
        if ($units !== null && array_filter($units, fn ($n) => ! is_int($n) && ! (is_string($n) && ctype_digit($n)) || (int) $n <= 0) !== []) {
            throw new CommandRejected('Unità collegate non valide.');
        }
        $units = array_values(array_unique(array_map('intval', $units ?? [])));

        $links = ['contact_id' => $contactId, 'property_request_id' => $requestId, 'property_id' => $propertyId,
            'owner_contact_id' => $ownerId, 'parcel_id' => $parcelId, 'unit_ids' => $units];
        if (! $access->linksOf($actor)->visible($links)) {
            throw new CommandRejected('Elemento collegato non accessibile.', 403);
        }
        foreach ([$assignee, ...$sharedMembers->all()] as $person) {
            if (! $access->linksOf($person)->visible($links)) {
                throw new CommandRejected('Il destinatario non può accedere a questo collegamento. Usa un immobile o una pratica autorizzata per entrambi.', 403);
            }
        }

        if (! in_array($type, ActivityCatalog::TYPES, true) || $title === '' || ($has('due_at') && $input['due_at'] !== null && $input['due_at'] !== '' && $due === null)) {
            throw new CommandRejected('Completa tipo, titolo e data dell’attività.');
        }
        if ($type === 'Promemoria' && $old === null && $followupOf === null) {
            throw new CommandRejected('Crea il promemoria insieme a una nuova attività.');
        }
        if ($type === 'Promemoria' && $old === null && ($due === null || $due->lte(now()))) {
            throw new CommandRejected('Per il promemoria indica titolo, data e ora future.');
        }
        if ($ownerId && $parcelId && ! $this->ownerBelongsToParcel($ownerId, $parcelId, $units)) {
            throw new CommandRejected('Proprietario e unità devono appartenere alla particella indicata.');
        }
        if (! in_array($priority, ActivityCatalog::PRIORITIES, true)) {
            throw new CommandRejected('Priorità non valida.');
        }
        if ($amount !== null && $amount !== '' && (! is_numeric($amount) || (float) $amount < 0)) {
            throw new CommandRejected('Valore economico non valido.');
        }
        if ($done && $agentId !== (int) $actor->user_id && $role !== 'admin') {
            throw new CommandRejected('Solo il destinatario può completare questa attività.', 403);
        }

        $outcome = $type === 'Promemoria' ? '' : Commands::text($pick('outcome', $old?->outcome), 300);
        if ($contactOperation !== null && $contactOperation !== '' && ! in_array($contactOperation, ActivityCatalog::CONTACT_OPERATIONS, true)) {
            throw new CommandRejected('Scegli se il contatto riguarda vendita o affitto.');
        }
        $contactOperation = $contactOperation ?: null;
        if ($contactOperation && $type === 'Promemoria') {
            throw new CommandRejected('Un contatto per vendita o affitto non può diventare un promemoria. Crea un promemoria separato.');
        }
        if ($contactOperation && $done && ! in_array($outcome, ActivityCatalog::outcomesForContact($contactOperation), true)) {
            throw new CommandRejected('Scegli un esito coerente con vendita o affitto.');
        }
        $crmOperation = $crmOperation ?: null;
        $crmCategory = $crmCategory ?: null;
        if (($crmOperation !== null && ! in_array($crmOperation, ActivityCatalog::CRM_OPERATIONS, true))
            || ($crmCategory !== null && ! in_array($crmCategory, ActivityCatalog::CRM_CATEGORIES, true))) {
            throw new CommandRejected('Tipo o categoria dell’attività cliente non validi.');
        }
        if (($crmOperation || $crmCategory) && (! $crmOperation || ! $crmCategory || ! $contactId || ! $requestId || ! $done || $role === 'scout')) {
            throw new CommandRejected('Un’attività cliente richiede cliente, richiesta, tipo e categoria e deve essere già svolta.');
        }

        $notes = Commands::text($pick('notes', $old?->notes));
        $answered = $contactOperation
            ? ! in_array($outcome, ActivityCatalog::UNANSWERED_OUTCOMES, true) && $outcome !== ''
            : (($has('answered') ? $input['answered'] : $old?->answered) === true);
        $status = $done ? 'Completata' : ($old?->status === 'Annullata' ? 'Annullata' : (in_array($old?->status, [null, 'Completata'], true) ? 'Da svolgere' : $old->status));

        $activity = $old ?? new Activity;
        $before = $old ? $old->only(['kind', 'subject', 'notes', 'outcome', 'status', 'scheduled_at', 'priority', 'assigned_to_user_id']) : null;
        $activity->forceFill([
            'user_id' => $old?->user_id ?? $actor->user_id,
            'created_by_user_id' => $old?->created_by_user_id ?? $actor->user_id,
            'assigned_to_user_id' => $agentId,
            'kind' => $type,
            'subject' => $title,
            'notes' => $notes === '' ? null : $notes,
            'scheduled_at' => $due,
            'status' => $status,
            'priority' => $priority,
            'outcome' => $outcome === '' ? null : $outcome,
            'internal' => ($has('internal') ? $input['internal'] : $old?->internal) !== false,
            'visibility' => $sharedWith !== [] ? 'workflow' : 'participants',
            'origin_role' => $old?->origin_role ?? $role,
            'contact_id' => $contactId, 'property_request_id' => $requestId, 'property_id' => $propertyId,
            'owner_contact_id' => $ownerId, 'parcel_id' => $parcelId,
            'interest' => Commands::text($pick('interest', $old?->interest)) ?: null,
            'event_type' => Commands::text($pick('event_type', $old?->event_type), 120) ?: null,
            'amount' => (float) ($amount ?: 0),
            'contact_operation' => $contactOperation, 'crm_operation' => $crmOperation, 'crm_category' => $crmCategory,
            'answered' => $answered,
            'completed_at' => $done ? ($old?->completed_at ?? $now) : null,
            'completed_by_user_id' => $done ? ($old?->completed_by_user_id ?? $agentId) : null,
            'outcome_confirmed_at' => $done && $outcome !== '' ? ($old?->outcome_confirmed_at ?? $now) : null,
            'outcome_confirmed_by_user_id' => $done && $outcome !== '' ? ($old?->outcome_confirmed_by_user_id ?? $actor->user_id) : null,
            'cancelled_at' => $old?->cancelled_at,
            'metadata' => (object) array_merge($old?->metadata ?? [], is_array($input['metadata'] ?? null) ? $input['metadata'] : []),
        ]);
        $activity->save();

        $activity->participants()->whereNotNull('user_id')->whereNotIn('user_id', $sharedWith)->delete();
        foreach (array_diff($sharedWith, $oldShared) as $userId) {
            $activity->participants()->create(['user_id' => $userId]);
        }
        $activity->units()->whereNotIn('cadastral_unit_id', $units)->delete();
        foreach (array_diff($units, $oldUnits) as $unitId) {
            if (! CadastralUnit::query()->whereKey($unitId)->exists()) {
                throw new CommandRejected('Unità collegate non valide.');
            }
            $activity->units()->create(['cadastral_unit_id' => $unitId]);
        }
        $activity->unsetRelation('participants')->unsetRelation('units');
        $activity->load(['participants', 'units']);

        if (! $old && $agentId !== (int) $actor->user_id) {
            Notifier::notify($agentId, 'Nuova attività assegnata', $activity->id);
        }
        foreach (array_filter($sharedWith, fn (int $id) => $id !== (int) $actor->user_id && ! in_array($id, $oldShared, true)) as $userId) {
            Notifier::notify($userId, 'Attività condivisa', $activity->id);
        }
        if ($old?->created_by_user_id && (int) $old->created_by_user_id !== (int) $actor->user_id) {
            Notifier::notify((int) $old->created_by_user_id, 'Attività aggiornata dal destinatario', $activity->id);
        }

        $reason = Commands::text($input['reason'] ?? '');
        if ($old && $reason !== '') {
            // The previous outcome stays in the register: before/after and the reason.
            ActivityEvent::query()->create([
                'activity_id' => $activity->id, 'user_id' => $actor->user_id, 'kind' => 'correction', 'occurred_at' => $now,
                'payload' => ['reason' => $reason, 'before' => $before, 'after' => $activity->only(array_keys($before))],
            ]);
        }
        $this->audit->record($old ? 'activity.save' : 'activity.create', $activity,
            $old && $reason !== '' ? ['before' => $before] : ['kind' => $activity->kind, 'subject' => $activity->subject], $reason ?: null);

        return $activity;
    }

    /** @param list<int> $units */
    private function ownerBelongsToParcel(int $ownerId, int $parcelId, array $units): bool
    {
        $rows = Ownership::query()->where('contact_id', $ownerId)
            ->where(fn (Builder $q) => $q->where('parcel_id', $parcelId)->orWhereHas('cadastralUnit', fn (Builder $u) => $u->where('parcel_id', $parcelId)))
            ->get(['parcel_id', 'cadastral_unit_id']);
        if ($rows->isEmpty()) {
            return false;
        }
        foreach ($units as $unitId) {
            if (! $rows->contains(fn ($r) => (int) $r->cadastral_unit_id === $unitId || $r->cadastral_unit_id === null)) {
                return false;
            }
        }

        return true;
    }

    private function id(mixed $value): ?int
    {
        return $value === null || $value === '' || (int) $value === 0 ? null : (int) $value;
    }

    public static function parseDate(mixed $value): ?Carbon
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return Carbon::parse(trim($value));
        } catch (\Throwable) {
            return null;
        }
    }
}
