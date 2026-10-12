<?php

namespace App\Gestionale\Today;

use App\Gestionale\Activities\ActivityAccess;
use App\Gestionale\Census\CensusScope;
use App\Models\Activity;
use App\Models\AgencyMembership;
use App\Models\CensusProposal;
use App\Models\ClientProfile;
use App\Models\PropertyRequest;
use App\Models\ScoutingAssignment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Work queues of the "Oggi" screen by role (lib/crm/today-work.ts todayWork). Each row carries only
 * presentation text and an existing authorized destination. Days are in Europe/Rome.
 */
final class TodayWork
{
    public const SUSPENDED_OUTCOMES = ['ACQ', 'Incarico acquisito', 'Appuntamento di acquisizione fissato', 'Appuntamento di acquisizione svolto'];

    /** activity.ts suspendedAcquisition on the stored kind/outcome. */
    public static function suspended(Activity $a): bool
    {
        return $a->kind === 'Appuntamento di acquisizione' || in_array(trim((string) $a->outcome), self::SUSPENDED_OUTCOMES, true);
    }

    /** activity.ts isActivityOverdue */
    public static function overdue(Activity $a, ?CarbonImmutable $now = null): bool
    {
        return ! in_array($a->status, ['Completata', 'Annullata'], true) && ! self::suspended($a)
            && $a->scheduled_at !== null && $a->scheduled_at < ($now ?? now());
    }

    /**
     * Activities the member can see, not cancelled (permissions.ts canSeeActivity, the same rule as the agenda:
     * created, assigned or shared with the member, and every link reachable). Completed ones are kept so that
     * callers can derive both the pending and the "worked" views from one query.
     *
     * @return Collection<int, Activity>
     */
    public static function visibleActivities(AgencyMembership $m): Collection
    {
        return app(ActivityAccess::class)->visible($m, fn (Builder $q) => $q->with(['contact', 'owner'])->where('status', '<>', 'Annullata'));
    }

    public static function work(AgencyMembership $m, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $role = $m->role;
        $today = $now->setTimezone('Europe/Rome')->toDateString();
        $dated = fn ($a) => $a->scheduled_at !== null;
        $order = fn ($a, $b) => ($a->scheduled_at <=> $b->scheduled_at) ?: ($a->id <=> $b->id);

        $visible = self::visibleActivities($m);
        $pending = $visible->reject(fn ($a) => $a->status === 'Completata' || self::suspended($a));
        $callbacks = $pending->filter(fn ($a) => $a->assigned_to_user_id === $m->user_id && in_array($a->kind, ['Telefonata', 'Promemoria'], true) && $dated($a))->sort($order)->values();
        $current = $pending->filter(fn ($a) => $dated($a) && (self::overdue($a, $now) || $a->scheduled_at->setTimezone('Europe/Rome')->toDateString() === $today))->sort($order)->values();
        $ownerCalls = $callbacks->filter(fn ($a) => $a->kind === 'Telefonata' && $a->owner_contact_id !== null)->values();

        $clients = collect();
        $requests = collect();
        if ($role !== 'scout') {
            $clients = ClientProfile::query()->with('contact')->where('agency_id', $m->agency_id)->activeRecords()
                ->whereIn('status', ['Nuovo', 'Da contattare', 'Da profilare'])
                ->when($role === 'crm', fn ($q) => $q->where('agent_user_id', $m->user_id))->orderBy('created_at')->orderBy('id')->get();
            $requests = PropertyRequest::query()->visibleTo($m)->activeRecords()->where('finished', false)->whereNotIn('status', ['Sospesa', 'Conclusa', 'Annullata'])
                ->orderBy('updated_at')->orderBy('id')->get();
        }
        $proposals = $role === 'admin'
            ? CensusProposal::query()->where('agency_id', $m->agency_id)->where('status', 'Da verificare')->orderBy('created_at')->orderBy('id')->get() : collect();
        $parcels = collect();
        if ($role === 'scout') {
            $worked = $visible->whereNotNull('parcel_id')->pluck('parcel_id')->all();
            $activeParcels = CensusScope::units($m)->where('o.state', 'Attivo')->whereNull('o.removed')->select('cu.parcel_id')->distinct();
            $parcels = ScoutingAssignment::query()->with('parcel.municipality')->where('agency_id', $m->agency_id)->where('user_id', $m->user_id)
                ->whereNotNull('parcel_id')->whereNull('cadastral_unit_id')->whereIn('parcel_id', $activeParcels)
                ->whereNotIn('parcel_id', $worked ?: [0])->orderBy('id')->get();
        }

        $short = fn (?string $v, int $max = 120) => mb_strlen((string) $v) > $max ? mb_substr((string) $v, 0, $max - 1).'…' : (string) $v;
        $activityRow = fn ($a) => ['id' => $a->id, 'title' => $short($a->subject), 'detail' => null, 'dueAt' => $a->scheduled_at, 'href' => route('gestionale.activities.index', ['id' => $a->id])];

        return [
            'role' => $role, 'today' => $today,
            'counts' => ['today' => $current->count(), 'callbacks' => $callbacks->count(), 'ownerCalls' => $ownerCalls->count(), 'clients' => $clients->count(),
                'requests' => $requests->count(), 'approvals' => $proposals->count(), 'parcelsToStart' => $parcels->count()],
            'queues' => [
                'today' => $current->take(5)->map($activityRow)->all(),
                'callbacks' => $callbacks->take(5)->map($activityRow)->all(),
                'ownerCalls' => $ownerCalls->take(5)->map($activityRow)->all(),
                'clients' => $clients->take(5)->map(fn ($c) => ['id' => $c->contact_id, 'title' => $short($c->contact?->display_name), 'detail' => $c->status, 'dueAt' => null, 'href' => route('gestionale.clients.show', $c->contact_id)])->all(),
                'requests' => $requests->take(5)->map(fn ($r) => ['id' => $r->id, 'title' => $short($r->title), 'detail' => 'Completa il profilo', 'dueAt' => null, 'href' => route('gestionale.requests.show', $r->id)])->all(),
                'approvals' => $proposals->take(5)->map(fn ($p) => ['id' => $p->id, 'title' => $short($p->payload['name'] ?? 'Correzione proposta'), 'detail' => $short($p->notes), 'dueAt' => null, 'href' => route('gestionale.home', ['filtro' => 'approvals'])])->all(),
                'parcelsToStart' => $parcels->take(5)->map(fn ($p) => ['id' => $p->parcel_id,
                    'title' => $short($p->parcel?->municipality?->name ?: 'Particella '.($p->parcel?->number ?? $p->parcel_id)),
                    'detail' => 'Assegnata, senza attività registrate', 'dueAt' => null,
                    'href' => route('gestionale.scouting.index', ['parcel' => $p->parcel_id])])->all(),
            ],
        ];
    }
}
