<?php

namespace App\Gestionale\Goals;

use App\Models\Concerns\AgencyScope;
use App\Models\Goal;
use App\Models\GoalVersion;
use App\Models\AgencyMembership;
use Illuminate\Support\Facades\DB;

/**
 * Reads goals and users of an agency as the plain arrays GoalRules works on (state.goals, state.users),
 * independent of the current-agency context so the engine can also run from jobs and commands.
 */
final class GoalStore
{
    /** @return list<array<string, mixed>> goals with their versions, oldest version first */
    public static function goals(int $agencyId): array
    {
        $goals = Goal::query()->withoutGlobalScope(AgencyScope::class)->where('agency_id', $agencyId)->orderBy('id')->get();
        if ($goals->isEmpty()) {
            return [];
        }
        $versions = GoalVersion::query()->withoutGlobalScope(AgencyScope::class)->where('agency_id', $agencyId)
            ->whereIn('goal_id', $goals->modelKeys())->orderBy('version')->get()->groupBy('goal_id');

        return $goals->map(fn (Goal $g) => [
            'id' => (int) $g->id,
            'key' => (string) $g->key,
            'readOnly' => (bool) $g->read_only,
            'versions' => ($versions[$g->id] ?? collect())->map(fn (GoalVersion $v) => self::version($v))->values()->all(),
        ])->filter(fn (array $g) => $g['versions'] !== [])->values()->all();
    }

    /** Defaults of saveGoal for the fields an older row may not have. */
    public static function version(GoalVersion $row): array
    {
        $d = $row->definition ?? [];
        $effective = $row->effective_from->toDateString();

        return [
            'id' => (int) $row->id, 'version' => (int) $row->version,
            'name' => (string) ($d['name'] ?? ''), 'description' => (string) ($d['description'] ?? ''),
            'category' => (string) ($d['category'] ?? 'Altro'), 'target' => (float) ($d['target'] ?? 1), 'unit' => (string) ($d['unit'] ?? 'eventi'),
            'period' => (string) ($d['period'] ?? 'Giornaliera'), 'metric' => (string) ($d['metric'] ?? 'events'),
            'events' => array_values($d['events'] ?? ['Attività completata']), 'outcomes' => array_values($d['outcomes'] ?? []),
            'dedup' => (string) ($d['dedup'] ?? 'event'),
            'users' => array_map('intval', $d['users'] ?? []), 'roles' => array_values($d['roles'] ?? []),
            'groups' => array_values($d['groups'] ?? []), 'branches' => array_values($d['branches'] ?? []),
            'agency' => (bool) ($d['agency'] ?? false), 'mode' => (string) ($d['mode'] ?? 'individual'),
            'start' => (string) ($d['start'] ?? $effective), 'end' => (string) ($d['end'] ?? ''),
            'effectiveFrom' => $effective, 'active' => (bool) ($d['active'] ?? true), 'deleted' => (bool) ($d['deleted'] ?? false),
            'priority' => (string) ($d['priority'] ?? 'Media'), 'order' => (float) ($d['order'] ?? 0), 'icon' => (string) ($d['icon'] ?? 'Obiettivo'),
            'weight' => (float) ($d['weight'] ?? 1), 'responsibleId' => isset($d['responsibleId']) ? (int) $d['responsibleId'] : null,
            'keepCancelled' => (bool) ($d['keepCancelled'] ?? false),
            'author' => $row->author_user_id !== null ? (int) $row->author_user_id : null,
            'createdAt' => $row->created_at?->format('Y-m-d H:i:s.u') ?? '', 'reason' => (string) ($row->reason ?? ''),
        ];
    }

    /** The part of a version kept in the jsonb definition (the rest has its own columns). */
    public static function definition(array $v): array
    {
        return array_diff_key($v, array_flip(['id', 'version', 'effectiveFrom', 'author', 'createdAt', 'reason', 'viewUntil']));
    }

    /**
     * Users of the agency (state.users): every membership, active or not.
     *
     * @return array<int, array<string, mixed>> by user id
     */
    public static function users(int $agencyId): array
    {
        $users = [];
        $rows = AgencyMembership::query()->where('agency_id', $agencyId)->join('users', 'users.id', '=', 'agency_memberships.user_id')
            ->orderBy('users.name')->get(['agency_memberships.user_id', 'agency_memberships.role', 'agency_memberships.group_name', 'agency_memberships.branch_name', 'agency_memberships.deactivated_at', 'users.name']);
        foreach ($rows as $row) {
            $users[(int) $row->user_id] = [
                'id' => (int) $row->user_id, 'name' => (string) $row->name, 'role' => (string) $row->role,
                'group' => $row->group_name, 'branch' => $row->branch_name, 'active' => $row->deactivated_at === null,
            ];
        }

        return $users;
    }

    public static function user(AgencyMembership $membership): array
    {
        return [
            'id' => (int) $membership->user_id, 'name' => (string) ($membership->user?->name ?? ''), 'role' => (string) $membership->role,
            'group' => $membership->group_name, 'branch' => $membership->branch_name, 'active' => $membership->isActive(),
        ];
    }

    public static function lock(int $agencyId): void
    {
        DB::select('select pg_advisory_xact_lock(?)', [crc32('gestionale-goals:'.$agencyId)]);
    }
}
