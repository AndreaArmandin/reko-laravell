<?php

namespace App\Gestionale\Actions\Goals;

use App\Gestionale\Audit;
use App\Gestionale\CommandRejected;
use App\Gestionale\Commands;
use App\Gestionale\Goals\GoalCatalog;
use App\Gestionale\Goals\GoalEngine;
use App\Gestionale\Goals\GoalRules;
use App\Gestionale\Goals\GoalStore;
use App\Models\AgencyMembership;
use App\Models\Goal;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * goal.save of the gestionale (goals.ts saveGoal): creates an objective or adds a new version to it.
 * History is never rewritten: a change is a new version with its reason and the date it takes effect,
 * never in the past; applying it from today to the running period needs an explicit confirmation.
 * An administrator assigns to people, roles, groups, branches or the whole agency; everybody else
 * sets simple personal objectives for themselves only ('simple' form).
 */
final class SaveGoal
{
    public const SIMPLE_KEYS = ['simple', 'name', 'target', 'period', 'description', 'start', 'end', 'users', 'effectiveFrom', 'reason', 'confirmCurrentPeriod'];

    public function __construct(private readonly GoalEngine $engine, private readonly Audit $audit) {}

    /**
     * @param  array<string, mixed>  $data
     * @return int the goal id
     */
    public function handle(AgencyMembership $actor, ?int $goalId, array $data, ?CarbonInterface $now = null): int
    {
        if (! $actor->isActive()) {
            throw new CommandRejected(Commands::RECORD_DENIED, 403);
        }
        $agencyId = (int) $actor->agency_id;
        $now = $now ?? now();

        return DB::transaction(function () use ($actor, $agencyId, $goalId, $data, $now) {
            GoalStore::lock($agencyId);
            $goals = collect(GoalStore::goals($agencyId))->keyBy('id');
            $users = GoalStore::users($agencyId);
            $me = GoalStore::user($actor);
            $administrator = $actor->role === 'admin';
            $old = $goalId !== null ? $goals->get($goalId) : null;
            if ($goalId !== null && $old === null) {
                throw new CommandRejected('Obiettivo non trovato.');
            }
            if (! $administrator && $old !== null && ! GoalRules::canEditPersonal($old, $me)) {
                throw new CommandRejected('Puoi modificare solo gli obiettivi personali creati da te.', 403);
            }
            $today = GoalCatalog::today($now);
            $previous = $old !== null ? end($old['versions']) : null;
            $simple = ($data['simple'] ?? false) === true;
            if (! $administrator && (! $simple || array_diff(array_keys($data), self::SIMPLE_KEYS) !== []
                || (array_key_exists('users', $data) && (! is_array($data['users']) || count($data['users']) !== 1 || (int) reset($data['users']) !== $me['id'])))) {
                throw new CommandRejected('Puoi fissare obiettivi soltanto per te.', 403);
            }

            $defaults = [
                'name' => '', 'description' => '', 'category' => 'Personale', 'target' => 1, 'unit' => 'attività', 'period' => 'Giornaliera', 'metric' => 'events',
                'events' => ['Attività completata'], 'outcomes' => [], 'dedup' => 'event', 'users' => [$me['id']], 'roles' => [], 'groups' => [], 'branches' => [],
                'agency' => false, 'mode' => 'individual', 'start' => $today, 'end' => '', 'effectiveFrom' => $today, 'active' => true, 'priority' => 'Media', 'order' => 0,
                'icon' => 'Obiettivo', 'weight' => 1, 'responsibleId' => $me['id'], 'keepCancelled' => false,
            ];
            $patch = $simple
                ? array_diff_key(array_intersect_key($data, array_flip(self::SIMPLE_KEYS)), array_flip(['simple', 'users', 'reason', 'confirmCurrentPeriod']))
                : $data;
            $input = $patch + ($previous !== null ? array_diff_key($previous, array_flip(['id', 'version', 'author', 'createdAt', 'reason', 'viewUntil'])) : []) + $defaults;
            // Existing hidden rules survive simple edits; only an explicit reassignment by the administrator replaces legacy recipients.
            if ($simple && array_key_exists('users', $data)) {
                $input['users'] = $data['users'];
                $input['roles'] = $input['groups'] = $input['branches'] = [];
                $input['agency'] = false;
            }
            if (! $administrator && $old === null) {
                $input['users'] = [$me['id']];
                $input['roles'] = $input['groups'] = $input['branches'] = [];
                $input['agency'] = false;
                $input['mode'] = 'individual';
                $input['responsibleId'] = $me['id'];
            }

            foreach (['events', 'outcomes', 'roles', 'groups', 'branches'] as $k) {
                if (! is_array($input[$k]) || array_filter($input[$k], fn ($v) => ! is_string($v) || mb_strlen($v) > 200) !== []) {
                    throw new CommandRejected('Controlla eventi e destinatari dell’obiettivo.');
                }
            }
            if (! is_array($input['users']) || array_filter($input['users'], fn ($v) => ! is_numeric($v)) !== []) {
                throw new CommandRejected('Controlla eventi e destinatari dell’obiettivo.');
            }
            $input['users'] = array_values(array_unique(array_map('intval', $input['users'])));
            foreach (['events', 'outcomes', 'roles', 'groups', 'branches'] as $k) {
                $input[$k] = array_values($input[$k]);
            }
            if (trim((string) ($input['name'] ?? '')) === '' || ! is_numeric($input['target']) || (float) $input['target'] <= 0
                || ! in_array($input['period'], GoalCatalog::PERIODS, true) || ! in_array($input['metric'], GoalCatalog::METRICS, true)
                || ! in_array($input['dedup'], GoalCatalog::DEDUPS, true) || ! in_array($input['mode'], GoalCatalog::MODES, true)
                || ! in_array($input['priority'], GoalCatalog::PRIORITIES, true) || ! is_numeric($input['order'])
                || ! is_numeric($input['weight']) || (float) $input['weight'] < 0 || $input['events'] === []) {
                throw new CommandRejected('Completa nome, valore, periodicità, regola ed eventi ammessi.');
            }
            $input['end'] = (string) ($input['end'] ?? '');
            $input['start'] = (string) $input['start'];
            $input['effectiveFrom'] = (string) $input['effectiveFrom'];
            if (! GoalCatalog::validDay($input['start']) || ($input['end'] !== '' && ! GoalCatalog::validDay($input['end']))
                || ($input['end'] !== '' && $input['end'] < $input['start']) || ($input['period'] === 'Intervallo personalizzato' && $input['end'] === '')
                || ! GoalCatalog::validDay($input['effectiveFrom']) || $input['effectiveFrom'] < $today) {
                throw new CommandRejected('Scegli date valide. Le modifiche non possono riscrivere periodi passati.');
            }
            $responsible = $input['responsibleId'] !== null ? (int) $input['responsibleId'] : null;
            if (array_filter($input['users'], fn (int $id) => ! isset($users[$id])) !== []
                || array_filter($input['roles'], fn (string $r) => ! in_array($r, GoalCatalog::ROLES, true)) !== []
                || ($responsible !== null && ! isset($users[$responsible]))) {
                throw new CommandRejected('Destinatario o responsabile non valido.');
            }
            if (! ($input['agency'] === true) && $input['users'] === [] && $input['roles'] === [] && $input['groups'] === [] && $input['branches'] === []) {
                throw new CommandRejected('Assegna l’obiettivo ad almeno un destinatario.');
            }
            $reason = trim((string) ($data['reason'] ?? ''));
            if ($old !== null && $reason === '') {
                throw new CommandRejected('Indica il motivo della modifica; la configurazione precedente resterà nello storico.');
            }
            if ($old !== null && $input['effectiveFrom'] <= $today && ($data['confirmCurrentPeriod'] ?? false) !== true) {
                throw new CommandRejected('Conferma esplicitamente l’applicazione al periodo in corso.');
            }

            $version = [
                'name' => mb_substr(trim((string) $input['name']), 0, 160), 'description' => mb_substr((string) ($input['description'] ?? ''), 0, 4000),
                'category' => mb_substr((string) ($input['category'] ?: 'Altro'), 0, 80), 'target' => (float) $input['target'],
                'unit' => mb_substr((string) ($input['unit'] ?: 'eventi'), 0, 40), 'period' => $input['period'], 'metric' => $input['metric'],
                'events' => $input['events'], 'outcomes' => $input['outcomes'], 'dedup' => $input['dedup'], 'users' => $input['users'],
                'roles' => $input['roles'], 'groups' => $input['groups'], 'branches' => $input['branches'], 'agency' => $input['agency'] === true,
                'mode' => $input['mode'], 'start' => $input['start'], 'end' => $input['end'], 'active' => ($input['active'] ?? true) !== false,
                'deleted' => (bool) ($input['deleted'] ?? false), 'priority' => $input['priority'], 'order' => (float) $input['order'],
                'icon' => mb_substr((string) ($input['icon'] ?: 'Obiettivo'), 0, 40), 'weight' => (float) $input['weight'], 'responsibleId' => $responsible,
                'keepCancelled' => ($input['keepCancelled'] ?? false) === true,
            ];

            $goal = $old !== null
                ? Goal::query()->withoutGlobalScopes()->where('agency_id', $agencyId)->findOrFail($old['id'])
                : tap(new Goal, fn (Goal $g) => $g->forceFill(['agency_id' => $agencyId, 'key' => 'goal-'.Str::uuid(), 'read_only' => false])->save());
            $number = $old !== null ? ((int) end($old['versions'])['version']) + 1 : 1;
            DB::table('goal_versions')->insert([
                'agency_id' => $agencyId, 'goal_id' => $goal->id, 'version' => $number, 'effective_from' => $input['effectiveFrom'],
                'definition' => json_encode($version, JSON_UNESCAPED_UNICODE), 'author_user_id' => $me['id'],
                'reason' => mb_substr($reason !== '' ? $reason : 'Creazione obiettivo', 0, 1000), 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->audit->record('goal.save', $goal, ['version' => $number, 'name' => $version['name']], $reason !== '' ? $reason : null);
            $this->engine->refreshLedger($agencyId, $now);

            return (int) $goal->id;
        });
    }
}
