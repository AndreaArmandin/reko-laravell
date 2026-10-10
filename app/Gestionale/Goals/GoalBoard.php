<?php

namespace App\Gestionale\Goals;

use App\Models\AgencyMembership;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What the viewer sees of the objectives: the read side of lib/crm/engine.ts (the goals and goalLedger
 * projected for the actor), goals.ts goalProgress and daily-goal-view.ts dailyGoalRows.
 * An administrator sees every goal and every ledger row. Everybody else sees only the versions
 * assigned to them, their own ledger rows and, for team goals, the total of the others as one row.
 */
final class GoalBoard
{
    /** @var list<array<string, mixed>> */
    private array $goals;

    /** @var array<int, array<string, mixed>> */
    private array $users;

    /** @var array<string, list<array<string, mixed>>> */
    private array $rows = [];

    private array $viewer;

    public function __construct(private readonly AgencyMembership $membership)
    {
        $agencyId = (int) $membership->agency_id;
        $this->viewer = GoalStore::user($membership);
        $this->users = GoalStore::users($agencyId);
        $all = GoalStore::goals($agencyId);
        $this->goals = $this->isAdmin() ? $all : array_values(array_filter(array_map(fn (array $g) => GoalRules::personalView($g, $this->viewer), $all)));
    }

    public function isAdmin(): bool
    {
        return $this->viewer['role'] === 'admin';
    }

    public function viewer(): array
    {
        return $this->viewer;
    }

    /** @return list<array<string, mixed>> goals visible to the viewer (data.goals) */
    public function goals(): array
    {
        return $this->goals;
    }

    /** @return array<int, array<string, mixed>> */
    public function users(): array
    {
        return $this->users;
    }

    /** @return list<array<string, mixed>> */
    public function activeUsers(): array
    {
        return array_values(array_filter($this->users, fn (array $u) => $u['active']));
    }

    /** Goals the viewer may edit (goals.tsx `editable`): an administrator all, anybody else their personal ones. */
    public function editable(): array
    {
        return array_values(array_filter($this->goals, fn (array $g) => $this->isAdmin() || GoalRules::canEditPersonal($g, $this->viewer)));
    }

    /**
     * goals.ts goalProgress.
     *
     * @return list<array<string, mixed>>
     */
    public function progress(array $user, ?string $day = null, bool $personal = false): array
    {
        $day ??= GoalCatalog::today();
        $items = [];
        foreach ($this->goals as $g) {
            $v = GoalRules::versionAt($g, $day);
            if ($v === null || $v['start'] > $day || ($v['end'] !== '' && $v['end'] < $day) || ! $v['active'] || $v['deleted']
                || (isset($v['viewUntil']) && $day >= $v['viewUntil'])
                || (($personal || $user['role'] !== 'admin') && ! GoalRules::assigned($v, $user))) {
                continue;
            }
            $window = GoalRules::window($v, $day);
            $all = $this->rows($g, $window['start']);
            $own = (! $personal && $user['role'] === 'admin') || $v['mode'] === 'team' ? $all : array_values(array_filter($all, fn (array $r) => $r['operatorId'] === $user['id']));
            $value = array_sum(array_column($own, 'value'));
            $last = collect($own)->filter(fn (array $r) => $r['value'] > 0)->sortByDesc(fn (array $r) => $r['at']->getTimestamp())->first();
            $items[] = [
                'goal' => $g, 'version' => $v, 'start' => $window['start'], 'end' => $window['end'], 'value' => $value,
                'missing' => max(0, $v['target'] - $value), 'complete' => $value >= $v['target'], 'events' => $own, 'last' => $last['at'] ?? null,
            ];
        }
        usort($items, fn (array $a, array $b) => (GoalRules::priorityIndex($a['version']['priority']) <=> GoalRules::priorityIndex($b['version']['priority']))
            ?: ($a['version']['order'] <=> $b['version']['order']));

        return $items;
    }

    /**
     * daily-goal-view.ts dailyGoalRows: the primary daily contact goal of each person (everybody active for an administrator).
     *
     * @return list<array{user: array, day: string, primary: ?array, additional: int}>
     */
    public function dailyRows(?string $day = null): array
    {
        $day ??= GoalCatalog::today();
        $users = $this->isAdmin() ? $this->activeUsers() : [$this->viewer];

        return array_map(function (array $user) use ($day) {
            $goals = array_values(array_filter($this->progress($user, $day, true),
                fn (array $i) => $i['version']['period'] === 'Giornaliera' && $i['version']['metric'] === 'contacts' && $i['version']['mode'] === 'individual'));

            return ['user' => $user, 'day' => $day, 'primary' => $goals[0] ?? null, 'additional' => max(0, count($goals) - 1)];
        }, $users);
    }

    /**
     * The ledger rows of a goal in the period starting on $start, as the viewer sees them.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(array $goal, string $start): array
    {
        $cache = $goal['id'].'|'.$start;
        if (isset($this->rows[$cache])) {
            return $this->rows[$cache];
        }
        $raw = DB::table('goal_ledger')->where('agency_id', $this->membership->agency_id)->where('goal_id', $goal['id'])->where('period_start', $start)
            ->orderBy('occurred_at')->orderBy('id')->get();
        $rows = [];
        $others = [];
        $teamVersions = array_column(array_filter($goal['versions'], fn (array $v) => $v['mode'] === 'team'), 'id');
        foreach ($raw as $r) {
            $row = [
                'id' => (string) $r->ledger_key, 'goalId' => (int) $r->goal_id, 'versionId' => (int) $r->goal_version_id, 'eventId' => (string) $r->event_key,
                'operatorId' => (int) $r->operator_user_id, 'subject' => (string) $r->subject, 'propertyId' => $r->property_id !== null ? (int) $r->property_id : null,
                'at' => CarbonImmutable::parse($r->occurred_at), 'start' => CarbonImmutable::parse($r->period_start)->toDateString(),
                'end' => CarbonImmutable::parse($r->period_end)->toDateString(), 'value' => (float) $r->value, 'reason' => (string) $r->reason,
                'rule' => (json_decode($r->rule, true)['label'] ?? ''),
            ];
            if ($this->isAdmin() || $row['operatorId'] === $this->viewer['id']) {
                $rows[] = $row;
            } elseif (in_array($row['versionId'], $teamVersions, true)) {
                $others[$row['versionId']][] = $row;
            }
        }
        foreach ($others as $versionId => $entries) {
            $rows[] = [...$entries[0], 'id' => 'team:'.$goal['id'].':'.$start, 'eventId' => 'team', 'operatorId' => 'team', 'subject' => '', 'propertyId' => null,
                'value' => array_sum(array_column($entries, 'value')), 'reason' => 'Contributo complessivo della squadra'];
        }

        return $this->rows[$cache] = $rows;
    }
}
