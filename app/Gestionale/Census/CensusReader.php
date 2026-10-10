<?php

namespace App\Gestionale\Census;

use App\Models\AgencyMembership;
use App\Models\Activity;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Legge dal database le schede di censimento nella forma dell'originale (census-query.ts / census-model.ts):
 * unità con contesto catastale, intestatari attuali con titolarità, ultimo esito e prossimo impegno.
 * Tutto passa da CensusScope: nessuna scheda fuori dalla vista del ruolo.
 */
final class CensusReader
{
    public const SOGLIA_MULTI_PROPRIETARIO = 2;

    /** Un solo Comune per volta più eventuali focus (unità, particella, proprietario). */
    public function __construct(private readonly AgencyMembership $m) {}

    /** Comuni con almeno una unità del censimento (censusMunicipalities). @return list<array{name:string,province:string,code:string}> */
    public function municipalities(): array
    {
        return CensusScope::units($this->m)->where('o.state', 'Attivo')->whereNull('o.removed')
            ->select('mu.name', 'mu.cadastral_code as code', 'tp.abbreviation as province')->distinct()
            ->orderBy('mu.name')->orderBy('mu.cadastral_code')->get()
            ->map(fn ($r) => ['name' => $r->name, 'province' => (string) $r->province, 'code' => $r->code])->all();
    }

    /**
     * @param  array{municipalityCode?:?string,unitId?:?int,unitIds?:list<int>,parcelId?:?int,ownerId?:?int}  $focus
     * @return Collection<int, array<string,mixed>> una voce per unità
     */
    public function entries(array $focus = []): Collection
    {
        $query = CensusScope::units($this->m)->select(
            'o.*', 'cu.parcel_id', 'cu.subalterno', 'cu.source_ref',
            'p.section as p_section', 'p.sheet as p_sheet', 'p.number as p_number', 'p.cadastral_kind as p_kind',
            'mu.name as mu_name', 'mu.cadastral_code as mu_code', 'tp.abbreviation as mu_province',
        );
        if (! empty($focus['municipalityCode'])) {
            $query->where('mu.cadastral_code', $focus['municipalityCode']);
        }
        if (! empty($focus['unitId'])) {
            $query->where('o.cadastral_unit_id', $focus['unitId']);
        }
        if (! empty($focus['unitIds'])) {
            $query->whereIn('o.cadastral_unit_id', array_map('intval', $focus['unitIds']));
        }
        if (! empty($focus['parcelId'])) {
            $query->where('cu.parcel_id', $focus['parcelId']);
        }
        if (! empty($focus['ownerId'])) {
            $query->whereExists(fn (Builder $w) => $w->selectRaw('1')->from('ownerships as w')
                ->whereColumn('w.cadastral_unit_id', 'o.cadastral_unit_id')->where('w.contact_id', $focus['ownerId'])->whereNull('w.valid_to'));
        }
        $rows = $query->get();
        if ($rows->isEmpty()) {
            return collect();
        }

        $unitIds = $rows->pluck('cadastral_unit_id')->all();
        $owners = $this->ownersOf($unitIds);
        $ownerIds = collect($owners)->flatMap(fn ($list) => array_column($list, 'id'))->unique()->values()->all();
        $counts = $this->ownerCounts($ownerIds);
        $outcomes = $this->outcomes($unitIds);
        $holdings = $this->holdingsHistory($unitIds);
        $upcoming = $this->upcoming($unitIds);
        $operators = DB::table('scouting_assignments')->where('agency_id', $this->m->agency_id)->whereNull('cadastral_unit_id')
            ->whereIn('parcel_id', $rows->pluck('parcel_id')->unique()->all())->orderBy('id')->pluck('user_id', 'parcel_id');
        $geometry = $this->parcelsWithGeometry($rows->pluck('parcel_id')->unique()->all());

        return $rows->map(function ($r) use ($owners, $counts, $outcomes, $holdings, $upcoming, $operators, $geometry) {
            $kind = $r->p_kind === 'T' ? 'Terreni' : 'Fabbricati';
            $context = ['municipality' => $r->mu_name, 'code' => $r->mu_code, 'province' => (string) $r->mu_province, 'kind' => $kind,
                'section' => (string) $r->p_section, 'sheet' => (string) $r->p_sheet, 'parcel' => (string) $r->p_number,
                'situationDate' => $r->situation_date ?? ''];
            $sub = $r->subalterno === null ? '' : (string) $r->subalterno;
            $unit = [
                'id' => (int) $r->cadastral_unit_id, 'sub' => $sub, 'category' => (string) $r->category, 'address' => (string) $r->address,
                'rawAddress' => (string) $r->raw_address, 'cadastralClass' => (string) $r->cadastral_class, 'consistency' => (string) $r->consistency,
                'floor' => (string) $r->floor, 'censusZone' => (string) $r->census_zone, 'income' => $r->income === null ? null : (float) $r->income,
                'batch' => (string) $r->batch, 'rawText' => (string) $r->raw_text, 'rawClassing' => (string) $r->raw_classing,
                'source' => $r->source, 'state' => $r->state, 'situationDate' => (string) ($r->situation_date ?? ''), 'lastVerified' => (string) ($r->last_verified ?? ''),
                'street' => $r->street, 'civic' => $r->civic, 'locality' => $r->locality, 'locationSourceAddress' => $r->location_source_address,
                'removed' => $r->removed === null ? null : json_decode($r->removed, true), 'catalogKey' => $r->catalog_key,
                'identityDetail' => (string) $r->identity_detail,
            ];
            $parcelAddress = $r->mu_name.' · F. '.$r->p_sheet.' P. '.$r->p_number;
            $location = self::location($unit, $parcelAddress);
            $list = collect($owners[$r->cadastral_unit_id] ?? [])->map(fn ($o) => $o + [
                'unitCount' => $counts[$o['id']] ?? 0, 'multiOwner' => ($counts[$o['id']] ?? 0) > self::SOGLIA_MULTI_PROPRIETARIO,
            ])->values()->all();
            $history = $outcomes[$r->cadastral_unit_id] ?? [];

            return [
                'parcelId' => (int) $r->parcel_id, 'unitId' => (int) $r->cadastral_unit_id, 'key' => $r->cadastral_unit_id,
                'context' => $context, 'unit' => $unit,
                'address' => self::unitAddress($location),
                'parcelAddress' => $parcelAddress,
                'street' => $location['street'], 'civic' => $location['civic'], 'locality' => $location['locality'],
                'needsReview' => $location['needsReview'], 'locationWarning' => $location['locationWarning'],
                'owners' => $list, 'history' => $history, 'latest' => $history[0] ?? null,
                'holdings' => $holdings[$r->cadastral_unit_id] ?? [],
                'upcoming' => $upcoming[$r->cadastral_unit_id] ?? null,
                'operator' => (string) ($operators[$r->parcel_id] ?? ''),
                'geographyStatus' => in_array((int) $r->parcel_id, $geometry, true) ? 'ready' : 'missing',
            ];
        })->values();
    }

    /**
     * Pagine di unità intestate a un proprietario, mantenendo la visibilità del ruolo e senza
     * caricare l'intero archivio in PHP prima di mostrare le prime dieci schede.
     * @return array{page:int,size:int,total:int,items:list<array<string,mixed>>}
     */
    public function ownerEntriesPage(int $ownerId, int $page = 1, int $size = 10): array
    {
        $page = max(1, $page);
        $size = max(1, min(50, $size));
        $owned = CensusScope::units($this->m)->where('o.state', 'Attivo')->whereNull('o.removed')
            ->whereExists(fn (Builder $w) => $w->selectRaw('1')->from('ownerships as w')
                ->join('contacts as c', 'c.id', '=', 'w.contact_id')
                ->whereColumn('w.cadastral_unit_id', 'o.cadastral_unit_id')
                ->whereColumn('w.agency_id', 'o.agency_id')->where('w.contact_id', $ownerId)
                ->whereNull('w.valid_to')->whereNull('c.removed_at'));
        $total = (clone $owned)->distinct()->count('o.cadastral_unit_id');
        $latestOutcome = DB::table('activity_units as au')->join('activities as a', 'a.id', '=', 'au.activity_id')
            ->whereColumn('au.cadastral_unit_id', 'o.cadastral_unit_id')->where('a.contact_id', $ownerId)
            ->whereNotNull('a.completed_at')->whereNotNull('a.outcome_confirmed_at')->whereNotNull('a.outcome')
            ->whereNull('a.cancelled_at')->where('a.kind', '!=', 'Promemoria')->selectRaw('max(a.outcome_confirmed_at)');
        $ids = (clone $owned)->orderByRaw('('.$latestOutcome->toSql().') asc nulls first', $latestOutcome->getBindings())
            ->orderByRaw('lower(coalesce(o.address, \'\')) asc')->orderBy('mu.name')->orderBy('p.sheet')->orderBy('p.number')
            ->orderBy('cu.subalterno')->orderBy('o.cadastral_unit_id')
            ->offset(($page - 1) * $size)->limit($size)->pluck('o.cadastral_unit_id')->map(fn ($id) => (int) $id)->all();
        $entries = $ids === [] ? collect() : $this->entries(['unitIds' => $ids])->keyBy('unitId');

        return ['page' => $page, 'size' => $size, 'total' => $total,
            'items' => collect($ids)->map(fn ($id) => $entries->get($id))->filter()->values()->all()];
    }

    /** unitLocation() di census-address.ts. */
    public static function location(array $unit, string $fallback = ''): array
    {
        $source = $unit['address'] !== '' ? $unit['address'] : $fallback;
        $parsed = SisterText::splitAddress($source);
        $locality = (string) ($unit['locality'] ?? '');
        $changed = $unit['locationSourceAddress'] !== null && SisterText::normalizeAddress((string) $unit['locationSourceAddress']) !== SisterText::normalizeAddress($source);
        if ($changed) {
            return $parsed + ['locality' => $locality, 'needsReview' => true, 'locationWarning' => 'L’indirizzo della fonte è cambiato dopo la correzione manuale. Verifica via e civico.'];
        }
        if ($unit['street'] === null && $unit['civic'] === null) {
            return ['street' => $parsed['street'], 'civic' => $parsed['civic'], 'locality' => $locality, 'needsReview' => $parsed['needsReview'],
                'locationWarning' => $parsed['needsReview'] ? 'Civico da verificare: l’indirizzo contiene numeri non separabili con certezza.' : ''];
        }

        return ['street' => (string) ($unit['street'] ?? $parsed['street']), 'civic' => (string) ($unit['civic'] ?? $parsed['civic']), 'locality' => $locality,
            'needsReview' => false, 'locationWarning' => ''];
    }

    public static function unitAddress(array $location): string
    {
        return SisterText::normalizeAddress($location['street'].($location['civic'] !== '' ? ' n. '.$location['civic'] : ''));
    }

    /** Intestatari attuali, per unità, con titolarità. @param list<int> $unitIds @return array<int, list<array<string,mixed>>> */
    public function ownersOf(array $unitIds): array
    {
        $out = [];
        foreach (array_chunk($unitIds, 2000) as $chunk) {
            $rows = DB::table('ownerships as w')->join('contacts as c', 'c.id', '=', 'w.contact_id')
                ->where('w.agency_id', $this->m->agency_id)->whereNull('w.valid_to')->whereNull('c.removed_at')
                ->whereIn('w.cadastral_unit_id', $chunk)
                ->select('w.id as ownership_id', 'w.cadastral_unit_id', 'w.details', 'w.right_type', 'w.share_numerator', 'w.share_denominator', 'c.*')
                ->orderBy('c.display_name')->orderBy('c.id')->get();
            $phones = DB::table('contact_channels')->where('kind', 'phone')->whereIn('contact_id', $rows->pluck('id')->unique()->all())
                ->orderByDesc('is_primary')->orderBy('id')->get()->groupBy('contact_id');
            foreach ($rows as $r) {
                $out[$r->cadastral_unit_id][] = self::owner($r, $phones[$r->id] ?? collect(), self::holding($r));
            }
        }

        return $out;
    }

    /** @return array<string,mixed> */
    public static function owner(object $c, Collection $phones, ?array $holding = null): array
    {
        return [
            'id' => (int) $c->id, 'name' => $c->display_name, 'pid' => (string) ($c->tax_code ?? $c->vat_number ?? ''),
            'firstName' => (string) $c->given_name, 'lastName' => (string) $c->family_name, 'companyName' => (string) $c->company_name,
            'birthDetails' => (string) $c->birth_details, 'recapito' => $c->recapito, 'notes' => (string) $c->notes,
            'tags' => json_decode($c->tags ?? '[]', true) ?: [], 'source' => $c->origin === 'sister' ? 'sister-text' : $c->origin,
            'contactHistory' => is_string($c->contact_history ?? null) ? (json_decode($c->contact_history, true) ?: []) : ($c->contact_history ?? []),
            'phones' => $phones->map(fn ($p) => ['number' => $p->value, 'kind' => (string) $p->label, 'status' => $p->status])->values()->all(),
            'importedBy' => $c->imported_by_user_id, 'holding' => $holding,
        ];
    }

    public static function holding(object $w): array
    {
        $d = is_string($w->details) ? (json_decode($w->details, true) ?: []) : (array) $w->details;
        $h = $d['holding'] ?? ['right' => (string) $w->right_type, 'fraction' => $w->share_denominator ? $w->share_numerator.'/'.$w->share_denominator : '', 'rawText' => ''];
        $h['verifiedAt'] = $d['verified_at'] ?? '';
        $h['livesThere'] = $d['lives_there'] ?? null;

        return $h;
    }

    /** contactText(): il recapito libero, altrimenti i telefoni salvati. */
    public static function contactText(array $owner): string
    {
        return $owner['recapito'] ?? implode(' / ', array_column($owner['phones'], 'number'));
    }

    /** Unità distinte attive per proprietario, limitabili ai contatti delle schede correnti. @param list<int>|null $contactIds @return array<int,int> */
    public function ownerCounts(?array $contactIds = null): array
    {
        if ($contactIds === []) {
            return [];
        }
        $query = CensusScope::units($this->m)->where('o.state', 'Attivo')->whereNull('o.removed')
            ->join('ownerships as w', fn ($j) => $j->on('w.cadastral_unit_id', '=', 'o.cadastral_unit_id')->on('w.agency_id', '=', 'o.agency_id'))
            ->join('contacts as c', 'c.id', '=', 'w.contact_id')->whereNull('w.valid_to')->whereNull('c.removed_at');
        if ($contactIds !== null) {
            $query->whereIn('w.contact_id', array_map('intval', $contactIds));
        }

        return $query->groupBy('w.contact_id')->selectRaw('w.contact_id, count(distinct o.cadastral_unit_id) as n')
            ->pluck('n', 'contact_id')->map(fn ($n) => (int) $n)->all();
    }

    /** Current and closed ownerships, kept as a verifiable property history. @param list<int> $unitIds @return array<int,list<array<string,mixed>>> */
    public function holdingsHistory(array $unitIds): array
    {
        $out = [];
        foreach (array_chunk($unitIds, 2000) as $chunk) {
            $rows = DB::table('ownerships as w')->join('contacts as c', 'c.id', '=', 'w.contact_id')
                ->where('w.agency_id', $this->m->agency_id)->whereIn('w.cadastral_unit_id', $chunk)
                ->select('w.*', 'c.display_name as owner_name', 'c.tax_code', 'c.vat_number')->orderBy('w.valid_from')->orderBy('w.id')->get();
            if ($rows->isEmpty()) {
                continue;
            }
            $details = $rows->mapWithKeys(fn ($row) => [$row->id => is_string($row->details) ? (json_decode($row->details, true) ?: []) : (array) $row->details]);
            $authorIds = $details->pluck('actor_user_id')->filter(fn ($id) => is_numeric($id))->map(fn ($id) => (int) $id)->unique()->all();
            $authors = $authorIds === [] ? collect() : DB::table('users')->whereIn('id', $authorIds)->pluck('name', 'id');
            foreach ($rows as $row) {
                $meta = $details[$row->id] ?? [];
                $out[$row->cadastral_unit_id][] = [
                    'id' => (int) $row->id,
                    'ownerId' => (int) $row->contact_id,
                    'owner' => $row->owner_name,
                    'pid' => (string) ($row->tax_code ?: $row->vat_number ?: ''),
                    'holding' => $meta['holding'] ?? self::holding($row),
                    'start' => $row->valid_from ? (string) $row->valid_from : '',
                    'end' => $row->valid_to ? (string) $row->valid_to : '',
                    'current' => $row->valid_to === null,
                    'source' => (string) ($row->source ?: ($meta['source'] ?? '')),
                    'reason' => (string) ($meta['reason'] ?? ''),
                    'kind' => (string) ($meta['kind'] ?? ''),
                    'rawText' => (string) ($meta['raw_text'] ?? ''),
                    'exactDateUnknown' => (bool) ($meta['exact_date_unknown'] ?? false),
                    'author' => $authors[(int) ($meta['actor_user_id'] ?? 0)] ?? 'Operatore precedente',
                ];
            }
        }

        return $out;
    }

    /** Esiti confermati (createCensusOutcomesFor), dal più recente. @param list<int> $unitIds @return array<int, list<array<string,mixed>>> */
    public function outcomes(array $unitIds): array
    {
        $out = [];
        $activityType = (new Activity)->getMorphClass();
        foreach (array_chunk($unitIds, 2000) as $chunk) {
            $rows = DB::table('activity_units as au')->join('activities as a', 'a.id', '=', 'au.activity_id')
                ->where('au.agency_id', $this->m->agency_id)->whereIn('au.cadastral_unit_id', $chunk)
                ->whereNotNull('a.completed_at')->whereNotNull('a.outcome_confirmed_at')->whereNotNull('a.outcome')->whereNull('a.cancelled_at')
                ->where('a.kind', '!=', 'Promemoria')
                ->select('au.cadastral_unit_id', 'a.*')->get();
            if ($rows->isEmpty()) {
                continue;
            }
            $activityIds = $rows->pluck('id')->map(fn ($id) => (int) $id)->unique()->all();
            $nextIds = $rows->pluck('next_activity_id')->filter()->map(fn ($id) => (int) $id)->unique()->all();
            $nextDates = $nextIds === [] ? collect() : DB::table('activities')->where('agency_id', $this->m->agency_id)
                ->whereIn('id', $nextIds)->pluck('scheduled_at', 'id');
            $correctedIds = DB::table('audit_events')->where('agency_id', $this->m->agency_id)
                ->where('auditable_type', $activityType)->whereIn('auditable_id', $activityIds)
                ->whereNotNull('reason')->where('action', 'like', 'activity.%')->pluck('auditable_id')
                ->map(fn ($id) => (int) $id)->flip();
            $names = DB::table('users')->whereIn('id', $rows->flatMap(fn ($r) => [$r->completed_by_user_id, $r->assigned_to_user_id, $r->created_by_user_id])->filter()->unique()->all())->pluck('name', 'id');
            $contacts = DB::table('contacts')->whereIn('id', $rows->pluck('contact_id')->filter()->unique()->all())->pluck('display_name', 'id');
            foreach ($rows as $a) {
                $meta = json_decode($a->metadata ?? '{}', true) ?: [];
                $readable = $this->canReadActivity($a);
                $authorId = $a->completed_by_user_id ?? $a->assigned_to_user_id ?? $a->created_by_user_id;
                $out[$a->cadastral_unit_id][] = [
                    'id' => (int) $a->id, 'type' => $a->kind, 'outcome' => $a->outcome,
                    'contactOperation' => $a->contact_operation ?? $meta['contact_operation'] ?? $meta['contactOperation'] ?? null,
                    'at' => Carbon::parse($a->outcome_confirmed_at)->toIso8601String(),
                    'author' => $names[$authorId] ?? 'Operatore precedente', 'authorId' => $authorId,
                    'ownerId' => $a->contact_id ? (int) $a->contact_id : null, 'owner' => $contacts[$a->contact_id] ?? 'Proprietario precedente',
                    'note' => $readable ? (string) $a->notes : '',
                    'next' => $readable && $a->next_activity_id && isset($nextDates[$a->next_activity_id])
                        ? Carbon::parse($nextDates[$a->next_activity_id])->toIso8601String() : '',
                    'corrected' => $correctedIds->has((int) $a->id),
                ];
            }
        }
        foreach ($out as &$list) {
            usort($list, fn ($x, $y) => strcmp($y['at'], $x['at']) ?: $y['id'] <=> $x['id']);
        }

        return $out;
    }

    /** Prossimo impegno visibile (attività non svolta e non annullata). @param list<int> $unitIds @return array<int, array<string,mixed>> */
    public function upcoming(array $unitIds): array
    {
        $out = [];
        foreach (array_chunk($unitIds, 2000) as $chunk) {
            $rows = DB::table('activity_units as au')->join('activities as a', 'a.id', '=', 'au.activity_id')
                ->where('au.agency_id', $this->m->agency_id)->whereIn('au.cadastral_unit_id', $chunk)
                ->whereNull('a.completed_at')->whereNull('a.cancelled_at')->where('a.status', '!=', 'Annullata')
                ->select('au.cadastral_unit_id', 'a.*')->orderBy('a.scheduled_at')->get();
            foreach ($rows as $a) {
                if (! $this->canReadActivity($a) || isset($out[$a->cadastral_unit_id])) {
                    continue;
                }
                $out[$a->cadastral_unit_id] = ['id' => (int) $a->id, 'type' => $a->kind, 'dueAt' => $a->scheduled_at ? Carbon::parse($a->scheduled_at)->toIso8601String() : ''];
            }
        }

        return $out;
    }

    /** canSeeActivity: il Responsabile tutto, gli altri ciò che hanno creato, che spetta loro o che è condiviso. */
    private function canReadActivity(object $a): bool
    {
        if ($this->m->role === 'admin') {
            return true;
        }
        $user = (int) $this->m->user_id;
        if ((int) $a->assigned_to_user_id === $user || (int) $a->created_by_user_id === $user) {
            return true;
        }

        return DB::table('activity_participants')->where('activity_id', $a->id)->where('user_id', $user)->exists();
    }

    /** @param list<int> $parcelIds @return list<int> particelle con un confine nella cartografia caricata */
    private function parcelsWithGeometry(array $parcelIds): array
    {
        return DB::table('parcel_versions')->whereIn('parcel_id', $parcelIds)->whereNotNull('boundary')->distinct()->pluck('parcel_id')->map(fn ($i) => (int) $i)->all();
    }

    /** @return CarbonInterface|null */
    public static function date(?string $value): ?CarbonInterface
    {
        return $value ? Carbon::parse($value) : null;
    }
}
