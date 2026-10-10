<?php

namespace App\Gestionale\Goals;

use App\Models\Activity;
use App\Models\Concerns\AgencyScope;
use App\Models\Contact;
use App\Models\Goal;
use App\Models\GoalVersion;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The objectives engine (lib/crm/goals.ts syncGoalEvents, recordGoalMilestone, refreshGoalLedger).
 *
 * The original rebuilds goalEvents from the activities and the goalLedger of the current period after
 * every mutation. Here the same rules run against the tables goal_events and goal_ledger, driven by the
 * model observers and domain listeners (see GoalsServiceProvider). Same behaviour:
 * - an activity counts only once completed (or an acquisition appointment confirmed), reminders never;
 * - the event keeps the instant it was first recorded; days are in Europe/Rome;
 * - the ledger of the current period is recomputed every time, closed periods stay untouched
 *   unless an administrator corrects a completed activity (then only the periods it appears in);
 * - one count per person, property or event (dedup), per operator or per team (mode).
 */
final class GoalEngine
{
    /** Creates the two initial goals of the agency (initial-goals.json) once. */
    public function ensureInitialGoals(int $agencyId, ?CarbonInterface $now = null): void
    {
        $today = GoalCatalog::today($now);
        $existing = DB::table('goals')->where('agency_id', $agencyId)->whereIn('key', array_column(GoalCatalog::INITIAL, 'key'))->count();
        if ($existing === count(GoalCatalog::INITIAL) && ! DB::table('goal_versions')->where('agency_id', $agencyId)->doesntExist()) {
            return;
        }
        foreach (GoalCatalog::INITIAL as $config) {
            DB::transaction(function () use ($agencyId, $config, $today) {
                $goalId = DB::table('goals')->where('agency_id', $agencyId)->where('key', $config['key'])->value('id');
                if ($goalId === null) {
                    $goalId = DB::table('goals')->insertGetId(['agency_id' => $agencyId, 'key' => $config['key'], 'read_only' => false, 'created_at' => now(), 'updated_at' => now()]);
                }
                if (DB::table('goal_versions')->where('goal_id', $goalId)->exists()) {
                    return;
                }
                $definition = array_diff_key($config, ['key' => true]) + [
                    'users' => [], 'groups' => [], 'branches' => [], 'agency' => false, 'mode' => 'individual', 'start' => $today, 'end' => '',
                    'active' => true, 'weight' => 1, 'responsibleId' => null, 'keepCancelled' => false,
                ];
                DB::table('goal_versions')->insert([
                    'agency_id' => $agencyId, 'goal_id' => $goalId, 'version' => 1, 'effective_from' => $today,
                    'definition' => json_encode($definition, JSON_UNESCAPED_UNICODE), 'author_user_id' => null,
                    'reason' => 'Configurazione iniziale richiesta', 'created_at' => now(), 'updated_at' => now(),
                ]);
            });
        }
    }

    /**
     * An activity was created, completed, corrected or removed: rebuild its event and the current ledger.
     * $corrected is true when an already completed activity changed (admin correction): the closed
     * periods it counted in are recomputed too.
     */
    public function activityChanged(Activity $activity, bool $corrected = false, ?CarbonInterface $now = null): void
    {
        $agencyId = (int) $activity->agency_id;
        DB::transaction(function () use ($activity, $agencyId, $corrected, $now) {
            GoalStore::lock($agencyId);
            $this->syncActivityEvent($activity, $now);
            $this->refreshLedger($agencyId, $now, $corrected ? (int) $activity->id : null);
        });
    }

    public function activityRemoved(int $agencyId, int $activityId, ?CarbonInterface $now = null): void
    {
        DB::transaction(function () use ($agencyId, $activityId, $now) {
            GoalStore::lock($agencyId);
            DB::table('goal_events')->where('agency_id', $agencyId)->where('event_key', 'activity:'.$activityId)->delete();
            $this->refreshLedger($agencyId, $now, $activityId);
        });
    }

    /** Rebuilds every activity event and the current ledger of an agency (command, repair after a bulk change). */
    public function resync(int $agencyId, ?CarbonInterface $now = null): int
    {
        $count = 0;
        DB::transaction(function () use ($agencyId, $now, &$count) {
            GoalStore::lock($agencyId);
            $this->ensureInitialGoals($agencyId, $now);
            $keep = [];
            Activity::query()->withoutGlobalScope(AgencyScope::class)->where('agency_id', $agencyId)->orderBy('id')->chunkById(500, function ($activities) use (&$keep, $now, &$count) {
                foreach ($activities as $activity) {
                    $key = $this->syncActivityEvent($activity, $now);
                    if ($key !== null) {
                        $keep[] = $key;
                        $count++;
                    }
                }
            });
            DB::table('goal_events')->where('agency_id', $agencyId)->whereNotNull('activity_id')->whereNotIn('event_key', $keep ?: ['-'])->delete();
            $this->refreshLedger($agencyId, $now);
        });

        return $count;
    }

    /**
     * goals.ts recordGoalMilestone: an event that is not an activity (completed request, published property,
     * price revision, negotiation). Recorded once per id.
     */
    public function recordMilestone(int $agencyId, string $key, int $operatorUserId, array $types, ?CarbonInterface $now = null, ?int $propertyId = null, string $subject = '', float $amount = 0): void
    {
        $at = CarbonImmutable::instance($now ?? now());
        DB::transaction(function () use ($agencyId, $key, $operatorUserId, $types, $at, $propertyId, $subject, $amount, $now) {
            GoalStore::lock($agencyId);
            $inserted = DB::table('goal_events')->insertOrIgnore([
                'agency_id' => $agencyId, 'event_key' => $key, 'activity_id' => null, 'operator_user_id' => $operatorUserId,
                'subject' => $subject !== '' ? $subject : ($propertyId !== null ? (string) $propertyId : $key), 'property_id' => $propertyId, 'parcel_id' => null,
                'occurred_at' => $at->format('Y-m-d H:i:s'), 'event_types' => json_encode(array_values($types)), 'outcome' => $types[0] ?? null,
                'answered' => false, 'completed' => true, 'amount' => $amount, 'cancelled' => false, 'appointment_key' => null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($inserted > 0) {
                $this->refreshLedger($agencyId, $now);
            }
        });
    }

    /**
     * goals.ts refreshGoalLedger. $correctedActivityId: also recompute the closed periods in which that
     * activity's event was counted.
     */
    public function refreshLedger(int $agencyId, ?CarbonInterface $now = null, ?int $correctedActivityId = null): void
    {
        $today = GoalCatalog::today($now);
        $this->ensureInitialGoals($agencyId, $now);
        $goals = GoalStore::goals($agencyId);
        $users = GoalStore::users($agencyId);
        $byGoal = collect($goals)->keyBy('id');

        // Periods to compute: the one of today for every goal in force, plus the closed ones of a corrected activity.
        $plans = [];
        foreach ($goals as $goal) {
            $v = GoalRules::versionAt($goal, $today);
            if ($v === null || $v['start'] > $today || ($v['end'] !== '' && $v['end'] < $today)) {
                continue;
            }
            $window = GoalRules::window($v, $today);
            $plans[] = [$goal, $v, $window['start'], $window['end'], false];
        }
        if ($correctedActivityId !== null) {
            $affected = DB::table('goal_ledger')->where('agency_id', $agencyId)->where('period_end', '<', $today)
                ->where('event_key', 'activity:'.$correctedActivityId)->get(['goal_id', 'goal_version_id', 'period_start', 'period_end']);
            foreach ($affected->unique(fn ($r) => $r->goal_id.'|'.$r->period_start) as $entry) {
                $goal = $byGoal[(int) $entry->goal_id] ?? null;
                $v = $goal ? collect($goal['versions'])->firstWhere('id', (int) $entry->goal_version_id) : null;
                if ($goal !== null && $v !== null) {
                    $plans[] = [$goal, $v, CarbonImmutable::parse($entry->period_start)->toDateString(), CarbonImmutable::parse($entry->period_end)->toDateString(), true];
                }
            }
        }

        DB::table('goal_ledger')->where('agency_id', $agencyId)->where('period_end', '>=', $today)->delete();
        if ($plans === []) {
            return;
        }
        $events = $this->loadEvents($agencyId, min(array_column($plans, 2)));
        $activities = $this->activityFacts($agencyId, $events);
        foreach ($plans as [$goal, $v, $start, $end, $closed]) {
            if ($closed) {
                DB::table('goal_ledger')->where('agency_id', $agencyId)->where('goal_id', $goal['id'])->where('period_start', $start)->delete();
            }
            $this->insertRows($agencyId, GoalRules::periodLedger($goal, $v, $start, $end, $events, $users, $activities), $events);
        }
    }

    /** @param list<array<string, mixed>> $rows */
    private function insertRows(int $agencyId, array $rows, array $events): void
    {
        if ($rows === []) {
            return;
        }
        $eventIds = [];
        foreach ($events as $e) {
            $eventIds[$e['id']] = $e['dbId'];
        }
        $now = now();
        $insert = array_map(fn (array $r) => [
            'agency_id' => $agencyId, 'goal_id' => $r['goalId'], 'goal_version_id' => $r['versionId'], 'goal_event_id' => $eventIds[$r['eventId']] ?? null,
            'event_key' => $r['eventId'], 'ledger_key' => $r['id'], 'operator_user_id' => $r['operatorId'], 'subject' => (string) $r['subject'],
            'property_id' => $r['propertyId'], 'occurred_at' => $r['at']->format('Y-m-d H:i:s'), 'period_start' => $r['start'], 'period_end' => $r['end'],
            'value' => $r['value'], 'reason' => $r['reason'], 'rule' => json_encode(['label' => $r['rule']], JSON_UNESCAPED_UNICODE),
            'created_at' => $now, 'updated_at' => $now,
        ], $rows);
        foreach (array_chunk($insert, 500) as $chunk) {
            DB::table('goal_ledger')->upsert($chunk, ['agency_id', 'ledger_key'], ['goal_version_id', 'goal_event_id', 'operator_user_id', 'subject', 'property_id', 'occurred_at', 'period_start', 'period_end', 'value', 'reason', 'rule', 'updated_at']);
        }
    }

    /** @return list<array<string, mixed>> events from the day before $sinceDay on (the day is judged in Europe/Rome afterwards) */
    private function loadEvents(int $agencyId, string $sinceDay): array
    {
        $since = CarbonImmutable::createFromFormat('!Y-m-d', $sinceDay)->subDay();

        return DB::table('goal_events')->where('agency_id', $agencyId)->where('occurred_at', '>=', $since)->orderBy('occurred_at')->orderBy('event_key')->get()->map(fn ($r) => [
            'id' => (string) $r->event_key, 'dbId' => (int) $r->id, 'activityId' => $r->activity_id !== null ? (int) $r->activity_id : '',
            'operatorId' => (int) $r->operator_user_id, 'subject' => (string) $r->subject, 'propertyId' => $r->property_id !== null ? (int) $r->property_id : null,
            'parcelId' => $r->parcel_id !== null ? (int) $r->parcel_id : null, 'at' => CarbonImmutable::parse($r->occurred_at),
            'types' => json_decode($r->event_types, true) ?: [], 'outcome' => (string) $r->outcome, 'answered' => (bool) $r->answered,
            'completed' => (bool) $r->completed, 'amount' => (float) $r->amount, 'cancelled' => (bool) $r->cancelled,
            'appointmentId' => $r->appointment_key ?? '',
        ])->all();
    }

    /** Facts of the activities the events refer to, as evaluate() needs them. */
    private function activityFacts(int $agencyId, array $events): array
    {
        $ids = array_values(array_unique(array_filter(array_map(fn ($e) => $e['activityId'], $events))));
        $ids = array_merge($ids, array_values(array_unique(array_filter(array_map(fn ($e) => $e['appointmentId'], $events), 'is_numeric'))));
        if ($ids === []) {
            return [];
        }
        $facts = [];
        Activity::query()->withoutGlobalScope(AgencyScope::class)->where('agency_id', $agencyId)->whereIn('id', array_unique($ids))->get()
            ->each(function (Activity $a) use (&$facts) {
                $facts[(string) $a->id] = [
                    'type' => $a->kind, 'contactOperation' => $a->contact_operation, 'confirmedAt' => self::confirmedAt($a),
                    'ownerId' => $a->owner_contact_id, 'parcelId' => $a->parcel_id, 'propertyId' => $a->property_id, 'interest' => $a->interest,
                    'agentId' => $a->assigned_to_user_id, 'dueAt' => $a->scheduled_at,
                ];
            });

        return $facts;
    }

    /** Appointment confirmation: not a column of the agenda (the acquisition path is suspended), read from metadata if present. */
    private static function confirmedAt(Activity $a): ?CarbonInterface
    {
        $value = $a->getAttribute('confirmed_at') ?? ($a->metadata['confirmed_at'] ?? $a->metadata['confirmedAt'] ?? null);

        return $value ? CarbonImmutable::parse($value) : null;
    }

    /**
     * goals.ts syncGoalEvents for one activity: writes (or removes) its event. Returns the event key when it counts.
     */
    private function syncActivityEvent(Activity $a, ?CarbonInterface $now = null): ?string
    {
        $agencyId = (int) $a->agency_id;
        $key = 'activity:'.$a->id;
        $done = $a->status === 'Completata';
        $confirmedAt = self::confirmedAt($a);
        $operator = $a->completed_by_user_id ?? $a->assigned_to_user_id;
        $reminder = $a->kind === 'Promemoria';
        // Migration does not turn old, unconfirmed demo outcomes into new achievements.
        $member = $operator !== null && DB::table('agency_memberships')->where('agency_id', $agencyId)->where('user_id', $operator)->exists();
        if ($reminder || ($a->completed_at === null && $confirmedAt === null) || ! $member) {
            DB::table('goal_events')->where('agency_id', $agencyId)->where('event_key', $key)->delete();

            return null;
        }
        $appointment = $a->kind === 'Appuntamento di acquisizione' && $confirmedAt !== null;
        $types = [];
        if ($appointment) {
            $types[] = 'Appuntamento di acquisizione fissato';
        }
        if ($done) {
            $types[] = 'Attività completata';
            if (in_array($a->kind, ['Telefonata', 'Messaggio', 'Email'], true) || ($a->kind === 'Prossima attività' && ! empty($a->contact_operation))) {
                $types[] = 'Contatto';
            }
            if ($a->kind === 'Telefonata') {
                $types[] = 'Chiamata effettuata';
            }
            if ($a->outcome_confirmed_at !== null && ! empty($a->outcome) && ! in_array($a->outcome, GoalCatalog::DERIVED_EVENT_TYPES, true)) {
                $types[] = $a->outcome;
            }
            if (! empty($a->event_type) && ! in_array($a->event_type, GoalCatalog::DERIVED_EVENT_TYPES, true)) {
                $types[] = $a->event_type;
            }
            if ($a->kind === 'Visita') {
                $types[] = 'Riscontro raccolto';
            }
            if ($a->kind === 'Proposta di immobile') {
                $types[] = 'Immobile proposto';
            }
            if ($a->kind === 'Appuntamento di acquisizione') {
                $types[] = 'Appuntamento di acquisizione svolto';
            }
        }
        if ($a->kind === 'Visita' && $confirmedAt !== null) {
            $types[] = 'Visita fissata';
        }
        $subject = $this->subjectFor($a);
        $at = $appointment ? $confirmedAt : ($a->completed_at ?? CarbonImmutable::instance($now ?? now()));
        $row = [
            'activity_id' => $a->id, 'operator_user_id' => $operator, 'subject' => $subject, 'property_id' => $a->property_id, 'parcel_id' => $a->parcel_id,
            'event_types' => json_encode(array_values(array_unique($types))), 'outcome' => $appointment ? 'Appuntamento di acquisizione fissato' : $a->outcome,
            'answered' => $a->answered === true, 'completed' => $done, 'amount' => (float) ($a->amount ?? 0), 'cancelled' => $a->status === 'Annullata',
            'appointment_key' => $appointment ? (string) $a->id : ($a->metadata['appointmentId'] ?? $a->metadata['appointment_id'] ?? null),
            'updated_at' => now(),
        ];
        // The event keeps the instant it was first recorded (old?.at).
        $updated = DB::table('goal_events')->where('agency_id', $agencyId)->where('event_key', $key)->update($row);
        if ($updated === 0) {
            DB::table('goal_events')->insert($row + ['agency_id' => $agencyId, 'event_key' => $key, 'occurred_at' => CarbonImmutable::instance($at)->format('Y-m-d H:i:s'), 'created_at' => now()]);
        }

        return $key;
    }

    /**
     * goals.ts subjectFor: who the activity is about, so one person counts once. Owner with tax code first,
     * then the owner, then the client. A contact with a tax code is the same person in any role.
     */
    private function subjectFor(Activity $a): string
    {
        $contactId = $a->owner_contact_id ?? $a->contact_id;

        return $contactId === null ? '' : self::subjectForContact((int) $a->agency_id, (int) $contactId, $a->owner_contact_id !== null);
    }

    public static function subjectForContact(int $agencyId, int $contactId, bool $owner = false): string
    {
        $taxCode = Contact::query()->withoutGlobalScope(AgencyScope::class)->where('agency_id', $agencyId)->whereKey($contactId)->value('tax_code');
        $pid = $taxCode ? strtoupper(preg_replace('/[^a-z0-9]/i', '', $taxCode)) : '';
        if ($pid !== '') {
            return 'cf:'.$pid;
        }

        return ($owner ? 'owner:' : 'client:').$contactId;
    }
}
