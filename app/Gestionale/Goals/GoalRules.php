<?php

namespace App\Gestionale\Goals;

use Carbon\CarbonImmutable;

/**
 * Pure rules of the objectives, ported from lib/crm/goals.ts and permissions.ts assignedGoal.
 * No database: goals, versions, events, users and activities are plain arrays, so the rules are
 * the same ones the original gestionale runs on its in-memory state.
 *
 * goal    = ['id' => int, 'key' => string, 'versions' => list<version> (oldest first), 'readOnly' => bool]
 * version = definition fields + id, version, effectiveFrom, author, createdAt, reason (+ viewUntil)
 * user    = ['id' => int, 'name' => string, 'role' => string, 'group' => ?string, 'branch' => ?string, 'active' => bool]
 * event   = ['id' (event key), 'activityId', 'operatorId', 'subject', 'propertyId', 'parcelId', 'at', 'types', 'outcome', 'answered', 'completed', 'amount', 'cancelled', 'appointmentId']
 */
final class GoalRules
{
    /** permissions.ts assignedGoal */
    public static function assigned(array $v, array $user): bool
    {
        return ($v['agency'] ?? false) === true
            || in_array((int) $user['id'], array_map('intval', $v['users'] ?? []), true)
            || in_array($user['role'], $v['roles'] ?? [], true)
            || (! empty($user['group']) && in_array($user['group'], $v['groups'] ?? [], true))
            || (! empty($user['branch']) && in_array($user['branch'], $v['branches'] ?? [], true));
    }

    /** goals.ts versionAt: the version in force on a day (latest effectiveFrom, then newest). */
    public static function versionAt(array $goal, string $day): ?array
    {
        $versions = array_values(array_filter($goal['versions'], fn (array $v) => $v['effectiveFrom'] <= $day));
        usort($versions, fn (array $a, array $b) => strcmp($b['effectiveFrom'], $a['effectiveFrom'])
            ?: strcmp((string) $b['createdAt'], (string) $a['createdAt'])
            ?: ($b['version'] <=> $a['version']));

        return $versions[0] ?? null;
    }

    /** goals.ts goalWindow */
    public static function window(array $v, string $day): array
    {
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $day)->setTime(12, 0);
        $start = $day;
        $end = $day;
        $period = $v['period'];
        if ($period === 'Settimanale') {
            $start = $date->subDays($date->dayOfWeekIso - 1)->toDateString();
            $end = GoalCatalog::shift($start, 6);
        }
        if ($period === 'Mensile' || $period === 'Trimestrale') {
            $first = $period === 'Mensile' ? $date->month - 1 : intdiv($date->month - 1, 3) * 3;
            $startDate = CarbonImmutable::create($date->year, $first + 1, 1);
            $start = $startDate->toDateString();
            $end = $startDate->addMonths($period === 'Mensile' ? 1 : 3)->subDay()->toDateString();
        }
        if ($period === 'Annuale') {
            $start = $date->year.'-01-01';
            $end = $date->year.'-12-31';
        }
        if ($period === 'Intervallo personalizzato') {
            $start = (string) $v['start'];
            $end = (string) $v['end'];
        }
        $vStart = (string) ($v['start'] ?? '');
        $vEnd = (string) ($v['end'] ?? '');

        return [
            'start' => $start < $vStart ? $vStart : $start,
            'end' => $vEnd !== '' && $end > $vEnd ? $vEnd : $end,
        ];
    }

    /**
     * goals.ts evaluate: what an event is worth for a version.
     *
     * @param  array<string, array<string, mixed>>  $activities  activity facts by activity id (contactOperation, type, confirmedAt, ownerId, parcelId, propertyId, interest, agentId, dueAt)
     * @return array{value: float, reason: string}
     */
    public static function evaluate(array $v, array $e, array $activities): array
    {
        if (($e['cancelled'] ?? false) && ! ($v['keepCancelled'] ?? false)) {
            return ['value' => 0.0, 'reason' => 'Annullato'];
        }
        if (! array_intersect($e['types'], $v['events'])) {
            return ['value' => 0.0, 'reason' => 'Evento non ammesso'];
        }
        if (($v['outcomes'] ?? []) !== [] && ! in_array($e['outcome'], $v['outcomes'], true)) {
            return ['value' => 0.0, 'reason' => 'Esito non ammesso'];
        }
        if ($v['metric'] === 'contacts') {
            $operation = $activities[(string) ($e['activityId'] ?? '')]['contactOperation'] ?? null;
            if (! $e['completed'] || ! in_array('Contatto', $e['types'], true) || ! $e['answered']
                || ! GoalCatalog::validContactOutcome($e['outcome'], $operation) || (string) $e['subject'] === '') {
                return ['value' => 0.0, 'reason' => 'Contatto senza risposta effettiva o esito valido'];
            }
        } elseif ($v['metric'] === 'appointments') {
            $a = $activities[(string) ($e['appointmentId'] ?? '')] ?? null;
            if ((string) ($e['activityId'] ?? '') !== (string) ($e['appointmentId'] ?? '')) {
                return ['value' => 0.0, 'reason' => 'L’appuntamento è conteggiato sulla sua scheda, non anche sul contatto'];
            }
            if ($a === null || $a['type'] !== 'Appuntamento di acquisizione' || empty($a['confirmedAt']) || empty($a['ownerId'])
                || (empty($a['parcelId']) && empty($a['propertyId']) && empty($a['interest'])) || empty($a['agentId']) || empty($a['dueAt'])) {
                return ['value' => 0.0, 'reason' => 'Appuntamento di acquisizione incompleto'];
            }
        } elseif (! $e['completed'] && ! in_array('Visita fissata', $e['types'], true) && ! in_array('Appuntamento di acquisizione fissato', $e['types'], true)) {
            return ['value' => 0.0, 'reason' => 'Attività non completata'];
        }

        return ['value' => $v['metric'] === 'amount' ? (float) $e['amount'] : 1.0, 'reason' => 'Conteggiato'];
    }

    /**
     * goals.ts periodLedger: the ledger rows of one version in one period. Counted once per person,
     * property or event (dedup), once per team or per operator depending on the mode.
     *
     * @param  list<array<string, mixed>>  $events
     * @param  array<int, array<string, mixed>>  $users  by user id
     * @return list<array<string, mixed>>
     */
    public static function periodLedger(array $goal, array $v, string $start, string $end, array $events, array $users, array $activities): array
    {
        if (! ($v['active'] ?? true) || ($v['deleted'] ?? false)) {
            return [];
        }
        $inPeriod = array_filter($events, function (array $e) use ($start, $end, $users, $v) {
            $day = GoalCatalog::day($e['at']);

            return $day >= $start && $day <= $end && isset($users[$e['operatorId']]) && self::assigned($v, $users[$e['operatorId']]);
        });
        usort($inPeriod, fn (array $a, array $b) => ($a['at'] <=> $b['at']) ?: strcmp($a['id'], $b['id']));

        $seen = [];
        $rows = [];
        foreach ($inPeriod as $e) {
            $result = self::evaluate($v, $e, $activities);
            $subject = match ($v['dedup']) {
                'person' => (string) $e['subject'],
                'property' => (string) ($e['propertyId'] ?: ($e['parcelId'] ? 'parcel:'.$e['parcelId'] : '')),
                default => $e['id'],
            };
            $key = ($v['mode'] === 'team' ? 'team' : $e['operatorId']).':'.$subject;
            if ($result['value'] > 0 && ($subject === '' || isset($seen[$key]))) {
                $result = ['value' => 0.0, 'reason' => $subject !== '' ? 'Duplicato nel periodo' : 'Identificativo mancante'];
            }
            if ($result['value'] > 0) {
                $seen[$key] = true;
            }
            $rows[] = [
                'id' => $goal['key'].':'.$start.':'.$e['id'], 'goalId' => $goal['id'], 'versionId' => $v['id'], 'eventId' => $e['id'],
                'operatorId' => $e['operatorId'], 'subject' => $e['subject'], 'propertyId' => $e['propertyId'], 'at' => $e['at'],
                'start' => $start, 'end' => $end, 'value' => $result['value'], 'reason' => $result['reason'],
                'rule' => $v['metric'].' · '.$v['dedup'].' · '.$v['period'],
            ];
        }

        return $rows;
    }

    /** goals.ts canEditPersonalGoal: a personal goal is one created by the user, only for the user, individual. */
    public static function canEditPersonal(array $goal, array $user): bool
    {
        if (($goal['readOnly'] ?? false) || $goal['versions'] === []) {
            return false;
        }
        if ((int) ($goal['versions'][0]['author'] ?? 0) !== (int) $user['id']) {
            return false;
        }
        foreach ($goal['versions'] as $v) {
            if (array_map('intval', $v['users']) !== [(int) $user['id']] || $v['roles'] !== [] || $v['groups'] !== []
                || $v['branches'] !== [] || ($v['agency'] ?? false) || $v['mode'] !== 'individual') {
                return false;
            }
        }

        return true;
    }

    /** goals.ts personalGoalView: the versions assigned to the user, each valid until the next version starts. */
    public static function personalView(array $goal, array $user): ?array
    {
        $ordered = $goal['versions'];
        usort($ordered, fn (array $a, array $b) => strcmp($a['effectiveFrom'], $b['effectiveFrom']) ?: strcmp((string) $a['createdAt'], (string) $b['createdAt']) ?: ($a['version'] <=> $b['version']));
        $versions = [];
        foreach ($ordered as $index => $v) {
            if (self::assigned($v, $user)) {
                $versions[] = isset($ordered[$index + 1]) ? [...$v, 'viewUntil' => $ordered[$index + 1]['effectiveFrom']] : $v;
            }
        }
        if ($versions === []) {
            return null;
        }

        return ['id' => $goal['id'], 'key' => $goal['key'], 'versions' => $versions, 'readOnly' => ! self::canEditPersonal($goal, $user)];
    }

    public static function priorityIndex(string $priority): int
    {
        $i = array_search($priority, GoalCatalog::PRIORITIES, true);

        return $i === false ? -1 : $i;
    }
}
