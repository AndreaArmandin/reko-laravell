<?php

namespace App\Gestionale\Census;

use App\Gestionale\CommandRejected;
use App\Models\AgencyMembership;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pagine dell'archivio: Postgres filtra e ordina, PHP idrata solo le schede della pagina.
 * L'ordine usa last_outcome_at e archive_sort, così la prima pagina non ricalcola l'ultima attività di ogni unità.
 */
final class ArchiveListing
{
    private const UNIT_FILTERS = ['municipality', 'municipalityCode', 'address', 'civic', 'locality', 'floor', 'sheet', 'parcel', 'sub', 'category',
        'hasOwner', 'hasContact', 'outcome', 'never', 'upcoming', 'name', 'lastName', 'cf', 'coownership', 'multiOwner', 'withoutSub', 'activity', 'date'];

    /** Filtri che, nella vista Proprietari, si applicano alla persona e non all'unità. */
    private const PERSON_FILTERS = ['name', 'lastName', 'cf', 'hasContact', 'multiOwner', 'count'];

    public function __construct(private readonly AgencyMembership $m) {}

    /**
     * @param  array<string,mixed>  $q
     * @return array{page:int,size:int,total:int,items:list<array<string,mixed>>,people:list<array<string,mixed>>,options:array<string,list<string>>,nextCursor:?array<string,mixed>}
     */
    public function run(array $q): array
    {
        if (! CensusScope::canUse($this->m)) {
            throw new CommandRejected(CensusScope::NOT_ALLOWED, 403);
        }
        $view = $q['view'] ?? 'Immobili';
        $rawFilters = $q['filters'] ?? [];
        foreach (CensusQuery::REMOVED_FILTERS as $removed) {
            unset($rawFilters[$removed]);
        }
        $mode = $rawFilters['_mode'][0] ?? null;
        $filters = in_array($mode, CensusQuery::MODES, true) ? CensusQuery::filtersForMode($rawFilters, $mode) : $rawFilters;
        $size = max(1, min(100, (int) floor((float) ($q['size'] ?? 20)) ?: 20));
        $page = max(1, (int) floor((float) ($q['page'] ?? 1)) ?: 1);
        $search = (string) ($q['search'] ?? '');
        $order = in_array($q['order'] ?? '', ['Da contattare', 'Ultima attività', 'Esito'], true) ? $q['order'] : 'Da contattare';
        $outcome = (string) ($q['firstOutcome'] ?? '');
        $q = [...$q, 'search' => $search, 'order' => $order, 'firstOutcome' => $outcome];

        $units = $this->units($filters, $q, $view);
        $reader = new CensusReader($this->m);
        if ($view === 'Proprietari') {
            $people = $this->people($units, $filters, $q, $page, $size, $reader);

            return ['page' => $page, 'size' => $size, 'total' => $people['total'], 'items' => [], 'people' => $people['people'],
                'options' => ['outcome' => CensusQuery::outcomes()], 'nextCursor' => $people['nextCursor']];
        }

        $terrain = $this->terrain($filters, $q);
        $total = (clone $units)->distinct()->count('o.cadastral_unit_id') + count($terrain);
        [$rows, $next] = $this->slice($units, $terrain, $q, $page, $size);
        $unitIds = $rows->where('kind', 'unit')->pluck('unit_id')->map(fn ($id) => (int) $id)->all();
        $entries = $unitIds === [] ? collect() : $reader->entries(['unitIds' => $unitIds])->keyBy('unitId');
        $terrainByParcel = collect($terrain)->keyBy(fn (object $row) => (int) $row->parcel_id);

        return [
            'page' => $page, 'size' => $size, 'total' => $total, 'people' => [], 'options' => ['outcome' => CensusQuery::outcomes()], 'nextCursor' => $next,
            'items' => $rows->map(function (object $row) use ($entries, $terrainByParcel) {
                if ($row->kind === 'terrain') {
                    return $terrainByParcel->get((int) $row->parcel_id)?->payload;
                }

                return $entries->get((int) $row->unit_id);
            })->filter()->values()->all(),
        ];
    }

    /** @param  array<string,list<string>>  $filters
     * @param  array<string,mixed>  $q */
    private function units(array $filters, array $q, string $view): Builder
    {
        $query = CensusScope::units($this->m)->where('o.state', 'Attivo')->whereNull('o.removed');
        if (! empty($q['unitId'])) {
            $query->where('o.cadastral_unit_id', (int) $q['unitId']);
        }
        if (! empty($q['parcelId'])) {
            $query->where('cu.parcel_id', (int) $q['parcelId']);
        }
        if (! empty($q['ownerId'])) {
            $ownerId = (int) $q['ownerId'];
            $query->whereExists(fn (Builder $w) => $this->currentOwnership($w)->where('w.contact_id', $ownerId));
        }
        $code = $filters['municipalityCode'][0] ?? null;
        if (is_string($code) && $code !== '') {
            $municipalityId = DB::table('municipalities')->where('cadastral_code', $code)->value('id');
            $query->where('o.municipality_id', $municipalityId === null ? 0 : (int) $municipalityId);
        }
        $this->applyFilters($query, $filters, $view === 'Proprietari');
        $search = trim((string) ($q['search'] ?? ''));
        if ($search !== '' && ($view !== 'Proprietari' || $this->categoryTerm($search) !== null)) {
            $this->whereText($query, $search);
        }

        return $query;
    }

    /** @param  array<string,list<string>>  $filters */
    private function applyFilters(Builder $query, array $filters, bool $people): void
    {
        foreach ($filters as $key => $terms) {
            if (! $terms || in_array($key, ['_mode', 'count', 'municipalityCode'], true) || ($people && in_array($key, self::PERSON_FILTERS, true))) {
                continue;
            }
            if (! in_array($key, self::UNIT_FILTERS, true)) {
                $query->whereRaw('false');

                continue;
            }
            $this->applyFilter($query, $key, array_values($terms));
        }
    }

    /** @param  list<string>  $terms */
    private function applyFilter(Builder $query, string $key, array $terms): void
    {
        $query->where(function (Builder $w) use ($key, $terms) {
            foreach ($terms as $term) {
                $w->orWhere(function (Builder $one) use ($key, $term) {
                    match ($key) {
                        'municipality' => $one->where('mu.name', $term),
                        'sheet' => $one->where('p.sheet', $term),
                        'parcel' => $one->where('p.number', $term),
                        'sub' => $one->where('cu.subalterno', $term),
                        'category', 'floor' => $one->where('o.'.$key, $term),
                        'withoutSub' => $term === 'Sì'
                            ? $one->where(fn (Builder $s) => $s->whereNull('cu.subalterno')->orWhere('cu.subalterno', ''))
                            : $one->whereRaw('false'),
                        'address' => $one->where('o.filter_address', 'ilike', $this->like($term)),
                        'locality' => $one->where('o.locality', 'ilike', $this->like($term)),
                        'civic' => $one->where('o.filter_civic', SisterText::normalizeCivic($term)),
                        'name', 'lastName', 'cf' => $one->whereExists(fn (Builder $e) => $this->ownerName($e, $key, $term)),
                        'hasOwner' => $this->flag($one, $term, fn (Builder $e) => $this->currentOwnership($e)),
                        'hasContact' => $this->flag($one, $term, fn (Builder $e) => $this->ownerWithContact($e)),
                        'coownership' => $term === 'Sì' ? $one->whereRaw($this->ownerCountSql().' > 1') : ($term === 'No' ? $one->whereRaw($this->ownerCountSql().' <= 1') : $one->whereRaw('false')),
                        'multiOwner' => $this->flag($one, $term, fn (Builder $e) => $e->whereExists(fn (Builder $o) => $this->multiOwner($o))),
                        'never' => $term === 'Sì' ? $one->whereNull('o.last_outcome_at') : ($term === 'No' ? $one->whereNotNull('o.last_outcome_at') : $one->whereRaw('false')),
                        'outcome' => $term === 'Nessun esito' ? $one->whereNull('o.last_outcome') : $one->where('o.last_outcome', $term),
                        'upcoming' => $this->flag($one, $term, fn (Builder $e) => $this->upcoming($e)),
                        'date' => $one->whereRaw('substring(o.last_outcome_at::text, 1, 10) = ?', [substr($term, 0, 10)]),
                        'activity' => $one->whereRaw('('.$this->latestActivitySql('a.kind').') = ?', [$term]),
                        default => $one->whereRaw('false'),
                    };
                });
            }
        });
    }

    /** @param  array<string,mixed>  $q
     * @param  list<object>  $terrain
     * @return array{0:Collection<int,object>,1:?array<string,mixed>} */
    private function slice(Builder $units, array $terrain, array $q, int $page, int $size): array
    {
        $order = (string) $q['order'];
        $outcome = (string) $q['firstOutcome'];
        $cursor = $this->cursor($q, $order, $outcome);
        $fetch = $size + 1;
        if ($order === 'Esito' && $terrain === []) {
            $rows = $this->esitoPage($units, $outcome, $cursor, ($page - 1) * $size, $fetch);
        } else {
            $ordered = $this->ordered(clone $units, $order, $outcome);
            $rows = $this->window($ordered, $terrain, $cursor, ($page - 1) * $size, $fetch, $order, $outcome);
        }
        $hasMore = $rows->count() > $size;
        $rows = $rows->take($size)->values();
        $last = $rows->last();

        return [$rows, $hasMore && $last !== null ? $this->cursorFrom($last, $order, $outcome) : null];
    }

    /** @param  list<object>  $terrain */
    private function window(Builder $ordered, array $terrain, ?array $cursor, int $offset, int $fetch, string $order, string $outcome): Collection
    {
        if ($terrain === []) {
            $query = $this->selectKeys(clone $ordered);
            if ($cursor !== null) {
                $this->applyAfter($query, $cursor, $order, $outcome);
            } else {
                $query->offset($offset);
            }

            return $query->limit($fetch)->get()->each(fn (object $row) => $row->sort_group = $this->group($row, $outcome));
        }
        if ($cursor !== null) {
            $query = $this->selectKeys(clone $ordered);
            $this->applyAfter($query, $cursor, $order, $outcome);
            $units = $query->limit($fetch)->get();
            $terrain = array_values(array_filter($terrain, fn (object $row) => $this->isAfter($row, $cursor, $order, $outcome)));

            return $this->merge($units, $terrain, $order, $outcome)->take($fetch);
        }
        $units = $this->selectKeys(clone $ordered)->limit($offset + $fetch)->get();

        return $this->merge($units, $terrain, $order, $outcome)->slice($offset, $fetch)->values();
    }

    private function esitoPage(Builder $units, string $target, ?array $cursor, int $offset, int $fetch): Collection
    {
        $preferred = $this->ordered($this->whereOutcome(clone $units, $target, true), 'Da contattare', $target);
        $other = $this->ordered($this->whereOutcome(clone $units, $target, false), 'Da contattare', $target);
        if ($cursor === null) {
            $preferredTotal = (clone $preferred)->count();
            if ($offset < $preferredTotal) {
                $rows = $this->selectKeys(clone $preferred)->offset($offset)->limit(min($fetch, $preferredTotal - $offset))->get();
                if ($rows->count() < $fetch) {
                    $rows = $rows->concat($this->selectKeys(clone $other)->limit($fetch - $rows->count())->get());
                }
            } else {
                $rows = $this->selectKeys(clone $other)->offset($offset - $preferredTotal)->limit($fetch)->get();
            }
        } elseif ((int) ($cursor['group'] ?? 0) === 0) {
            $this->applyAfter($preferred, $cursor, 'Da contattare', $target);
            $rows = $this->selectKeys(clone $preferred)->limit($fetch)->get();
            if ($rows->count() < $fetch) {
                $rows = $rows->concat($this->selectKeys(clone $other)->limit($fetch - $rows->count())->get());
            }
        } else {
            $this->applyAfter($other, $cursor, 'Da contattare', $target);
            $rows = $this->selectKeys(clone $other)->limit($fetch)->get();
        }

        return $rows->each(fn (object $row) => $row->sort_group = $this->group($row, $target));
    }

    private function ordered(Builder $query, string $order, string $outcome): Builder
    {
        if ($order === 'Esito') {
            $query->orderByRaw("case when coalesce(o.last_outcome, 'Nessun esito') = ? then 0 else 1 end asc", [$outcome]);
        }
        if ($order === 'Ultima attività') {
            return $query->orderByRaw('o.last_outcome_at desc nulls last')->orderBy('o.archive_sort');
        }

        return $query->orderByRaw('o.last_outcome_at asc nulls first')->orderBy('o.archive_sort');
    }

    /** @param  array<string,mixed>  $cursor */
    private function applyAfter(Builder $query, array $cursor, string $order, string $outcome): void
    {
        $at = isset($cursor['at']) && $cursor['at'] !== '' ? (string) $cursor['at'] : null;
        $key = (string) ($cursor['key'] ?? '');
        if ($order === 'Esito') {
            $group = (int) ($cursor['group'] ?? 0);
            $query->where(function (Builder $w) use ($group, $at, $key, $outcome) {
                if ($group === 0) {
                    $w->where(function (Builder $same) use ($at, $key, $outcome) {
                        $this->whereOutcome($same, $outcome, true);
                        $this->afterNullsFirst($same, $at, $key);
                    })->orWhere(fn (Builder $rest) => $this->whereOutcome($rest, $outcome, false));
                } else {
                    $this->whereOutcome($w, $outcome, false);
                    $this->afterNullsFirst($w, $at, $key);
                }
            });

            return;
        }
        $order === 'Ultima attività' ? $this->afterNullsLast($query, $at, $key) : $this->afterNullsFirst($query, $at, $key);
    }

    private function afterNullsFirst(Builder $query, ?string $at, string $key): void
    {
        if ($at === null) {
            $query->where(fn (Builder $w) => $w->where(fn (Builder $same) => $same->whereNull('o.last_outcome_at')->where('o.archive_sort', '>', $key))->orWhereNotNull('o.last_outcome_at'));

            return;
        }
        $query->whereNotNull('o.last_outcome_at')->where(fn (Builder $w) => $w->where('o.last_outcome_at', '>', $at)
            ->orWhere(fn (Builder $same) => $same->where('o.last_outcome_at', '=', $at)->where('o.archive_sort', '>', $key)));
    }

    private function afterNullsLast(Builder $query, ?string $at, string $key): void
    {
        if ($at === null) {
            $query->whereNull('o.last_outcome_at')->where('o.archive_sort', '>', $key);

            return;
        }
        $query->where(fn (Builder $w) => $w->where(fn (Builder $earlier) => $earlier->whereNotNull('o.last_outcome_at')->where(fn (Builder $c) => $c->where('o.last_outcome_at', '<', $at)
            ->orWhere(fn (Builder $same) => $same->where('o.last_outcome_at', '=', $at)->where('o.archive_sort', '>', $key))))->orWhereNull('o.last_outcome_at'));
    }

    private function whereOutcome(Builder $query, string $target, bool $match): Builder
    {
        if ($target === 'Nessun esito') {
            return $match ? $query->whereNull('o.last_outcome') : $query->whereNotNull('o.last_outcome');
        }

        return $match
            ? $query->where('o.last_outcome', $target)
            : $query->where(fn (Builder $w) => $w->whereNull('o.last_outcome')->orWhere('o.last_outcome', '!=', $target));
    }

    private function selectKeys(Builder $query): Builder
    {
        return $query->selectRaw("'unit' as kind, o.cadastral_unit_id as unit_id, cu.parcel_id as parcel_id, o.last_outcome, o.last_outcome_at, o.archive_sort");
    }

    /**
     * @param  array<string,list<string>>  $filters
     * @param  array<string,mixed>  $q
     * @return array{total:int,people:list<array<string,mixed>>,nextCursor:?array<string,mixed>}
     */
    private function people(Builder $units, array $filters, array $q, int $page, int $size, CensusReader $reader): array
    {
        $owners = DB::table('contacts as c')->where('c.agency_id', $this->m->agency_id)->whereNull('c.removed_at')
            ->whereExists(function (Builder $e) use ($units) {
                $e->selectRaw('1')->from('ownerships as w')->whereColumn('w.contact_id', 'c.id')
                    ->where('w.agency_id', $this->m->agency_id)->whereNull('w.valid_to')
                    ->whereIn('w.cadastral_unit_id', (clone $units)->select('o.cadastral_unit_id'));
            });
        $this->applyPersonFilters($owners, $filters);
        $search = trim((string) ($q['search'] ?? ''));
        if ($search !== '' && $this->categoryTerm($search) === null) {
            $this->wherePersonSearch($owners, $units, $search);
        }
        $order = (string) ($q['order'] ?? 'Da contattare');
        $total = (clone $owners)->count();
        $cursor = $this->cursor($q, $order, (string) ($q['firstOutcome'] ?? ''));
        $fetch = $size + 1;
        $listed = (clone $owners)->select('c.*')->orderBy('c.display_name')->orderBy('c.id');
        if ($cursor !== null) {
            $key = (string) ($cursor['key'] ?? '');
            $afterId = (int) ($cursor['at'] ?? 0);
            $listed->where(fn (Builder $w) => $w->where('c.display_name', '>', $key)
                ->orWhere(fn (Builder $same) => $same->where('c.display_name', $key)->where('c.id', '>', $afterId)));
        } else {
            $listed->offset(($page - 1) * $size);
        }
        $rows = $listed->limit($fetch)->get();
        $hasMore = $rows->count() > $size;
        $rows = $rows->take($size)->values();
        $ids = $rows->pluck('id')->map(fn ($id) => (int) $id)->all();
        $counts = $reader->ownerCounts($ids);
        $phones = DB::table('contact_channels')->where('kind', 'phone')->whereIn('contact_id', $ids)->orderByDesc('is_primary')->orderBy('id')->get()->groupBy('contact_id');
        $matching = $ids === [] ? collect() : DB::table('ownerships as w')->where('w.agency_id', $this->m->agency_id)->whereNull('w.valid_to')
            ->whereIn('w.contact_id', $ids)->whereIn('w.cadastral_unit_id', (clone $units)->select('o.cadastral_unit_id'))
            ->get(['w.contact_id', 'w.cadastral_unit_id'])->groupBy('contact_id');
        $people = $rows->map(function (object $contact) use ($counts, $phones, $matching) {
            $count = $counts[(int) $contact->id] ?? 0;

            return [
                'owner' => CensusReader::owner($contact, $phones->get($contact->id, collect())),
                'count' => $count,
                'latest' => null,
                'multiOwner' => $count > CensusReader::SOGLIA_MULTI_PROPRIETARIO,
                'matching' => $matching->get($contact->id, collect())->pluck('cadastral_unit_id')->map(fn ($id) => (int) $id)->all(),
            ];
        })->all();
        $last = $rows->last();

        return ['total' => $total, 'people' => $people, 'nextCursor' => $hasMore && $last !== null ? ['order' => $order, 'outcome' => '', 'at' => (string) $last->id, 'key' => (string) $last->display_name, 'group' => 0] : null];
    }

    /** @param  array<string,list<string>>  $filters */
    private function applyPersonFilters(Builder $owners, array $filters): void
    {
        foreach (['name', 'lastName', 'cf'] as $key) {
            foreach ($filters[$key] ?? [] as $term) {
                $owners->whereExists(fn (Builder $e) => $this->ownerName($e, $key, $term)->whereColumn('w.contact_id', 'c.id'));
            }
        }
        if (! empty($filters['hasContact'])) {
            $owners->where(function (Builder $w) use ($filters) {
                foreach ($filters['hasContact'] as $term) {
                    $term === 'Sì'
                        ? $w->orWhere(fn (Builder $one) => $this->contactHasChannel($one))
                        : $w->orWhere(fn (Builder $one) => $one->whereNotExists(fn (Builder $missing) => $this->contactHasChannel($missing->whereColumn('c2.id', 'c.id'))));
                }
            });
        }
        foreach ($filters['count'] ?? [] as $term) {
            $owners->whereRaw($this->globalOwnerCountSql().($term === 'Più di uno' ? ' > 1' : ' = '.(int) $term));
        }
        if (! empty($filters['multiOwner'])) {
            $owners->where(function (Builder $w) use ($filters) {
                foreach ($filters['multiOwner'] as $term) {
                    $sql = $this->globalOwnerCountSql().' > '.CensusReader::SOGLIA_MULTI_PROPRIETARIO;
                    $term === 'Sì' ? $w->orWhereRaw($sql) : $w->orWhereRaw('not ('.$sql.')');
                }
            });
        }
    }

    /** @param  array<string,mixed>  $q */
    private function wherePersonSearch(Builder $owners, Builder $units, string $search): void
    {
        $tokens = $this->tokens($search);
        $owners->where(function (Builder $w) use ($tokens, $search, $units) {
            $w->where(function (Builder $name) use ($tokens) {
                foreach ($tokens as $token) {
                    $this->whereToken($name, $this->foldedContact(), $token);
                }
            });
            if ($this->isPhone($search)) {
                $digits = $this->phoneDigits($search);
                $w->orWhere(fn (Builder $phone) => $this->contactHasChannel($phone, $digits));
            }
            $address = clone $units;
            foreach ($tokens as $token) {
                $this->whereToken($address, 'o.search_text', $token);
            }
            $w->orWhereExists(function (Builder $e) use ($address) {
                $e->selectRaw('1')->from('ownerships as w')->whereColumn('w.contact_id', 'c.id')
                    ->where('w.agency_id', $this->m->agency_id)->whereNull('w.valid_to')
                    ->whereIn('w.cadastral_unit_id', $address->select('o.cadastral_unit_id'));
            });
        });
    }

    private function contactHasChannel(Builder $query, ?string $digits = null): void
    {
        $query->where(function (Builder $w) use ($digits) {
            $w->whereRaw("c.recapito is not null and btrim(c.recapito) <> ''".($digits === null ? '' : " and regexp_replace(c.recapito, '\\D', '', 'g') like ?"), $digits === null ? [] : ['%'.$digits.'%'])
                ->orWhereExists(function (Builder $e) use ($digits) {
                    $e->selectRaw('1')->from('contact_channels as ch')->whereColumn('ch.contact_id', 'c.id')->where('ch.kind', 'phone')
                        ->whereRaw("nullif(btrim(ch.value), '') is not null");
                    if ($digits !== null) {
                        $e->whereRaw("regexp_replace(ch.value, '\\D', '', 'g') like ?", ['%'.$digits.'%']);
                    }
                });
        });
    }

    /**
     * @param  array<string,list<string>>  $filters
     * @param  array<string,mixed>  $q
     * @return list<object>
     */
    private function terrain(array $filters, array $q): array
    {
        if (! Schema::hasTable('agency_terrain_acquisitions')) {
            return [];
        }
        $code = $filters['municipalityCode'][0] ?? null;
        $rows = array_values(array_filter(
            TerrainParcelReader::archiveRows($this->m, is_string($code) ? $code : null, isset($q['parcelId']) ? (int) $q['parcelId'] : null),
            fn (array $row) => $this->matchesTerrain($row, $filters, $q),
        ));
        if ($rows === []) {
            return [];
        }
        $sorts = DB::table('parcels as p')->join('municipalities as m', 'm.id', '=', 'p.municipality_id')->whereIn('p.id', array_column($rows, 'parcelId'))
            ->selectRaw("p.id, lower(m.name || ' · Terreni · F. ' || p.sheet || ' P. ' || p.number) || chr(1) || p.sheet || chr(1) || p.number || chr(1) || '' || chr(1) || lpad(p.id::text, 16, '0') as archive_sort")
            ->pluck('archive_sort', 'id');
        $outcome = (string) ($q['firstOutcome'] ?? '');

        return array_map(function (array $row) use ($sorts, $outcome) {
            $object = (object) ['kind' => 'terrain', 'unit_id' => null, 'parcel_id' => $row['parcelId'], 'last_outcome' => null, 'last_outcome_at' => null,
                'archive_sort' => (string) ($sorts[$row['parcelId']] ?? ''), 'payload' => $row];
            $object->sort_group = $this->group($object, $outcome);

            return $object;
        }, $rows);
    }

    /** @param  array<string,list<string>>  $filters
     * @param  array<string,mixed>  $q */
    private function matchesTerrain(array $row, array $filters, array $q): bool
    {
        if (! empty($q['unitId']) || ! empty($q['ownerId']) || (! empty($q['parcelId']) && $row['parcelId'] !== (int) $q['parcelId'])) {
            return false;
        }
        $c = $row['context'];
        $values = ['municipality' => $c['municipality'], 'municipalityCode' => $c['code'], 'address' => $row['address'], 'civic' => '',
            'locality' => '', 'floor' => '', 'sheet' => $c['sheet'], 'parcel' => $c['parcel'], 'sub' => '', 'category' => '',
            'hasOwner' => 'No', 'hasContact' => 'No', 'outcome' => 'Nessun esito', 'never' => 'Sì', 'upcoming' => 'No', 'name' => '', 'lastName' => '', 'cf' => '',
            'coownership' => 'No', 'multiOwner' => 'No', 'withoutSub' => 'Sì', 'activity' => '', 'date' => ''];
        foreach ($filters as $key => $terms) {
            if (! $terms || in_array($key, ['count', '_mode'], true)) {
                continue;
            }
            $value = (string) ($values[$key] ?? '');
            $ok = collect($terms)->contains(fn ($term) => $key === 'civic' ? SisterText::normalizeCivic($value) === SisterText::normalizeCivic($term)
                : (in_array($key, ['address', 'locality', 'name', 'lastName', 'cf'], true) ? mb_stripos($value, $term) !== false : $value === $term));
            if (! $ok) {
                return false;
            }
        }
        $search = trim((string) ($q['search'] ?? ''));

        return $search === '' || SisterText::matches(implode(' ', [$row['address'], CensusQuery::reference($c, null), $c['municipality'], $c['sheet'], $c['parcel']]), $search);
    }

    private function whereText(Builder $query, string $search): void
    {
        if (($category = $this->categoryTerm($search)) !== null) {
            $query->whereRaw("upper(replace(coalesce(o.category, ''), ' ', '')) = ?", [$category]);

            return;
        }
        $tokens = $this->tokens($search);
        if ($this->isPhone($search)) {
            $digits = $this->phoneDigits($search);
            $query->where(function (Builder $w) use ($tokens, $digits) {
                $w->where(function (Builder $text) use ($tokens) {
                    $this->whereAddressOrOwner($text, $tokens);
                })->orWhereExists(fn (Builder $e) => $this->phoneOnUnit($e, $digits));
            });

            return;
        }
        $this->whereAddressOrOwner($query, $tokens);
    }

    /** @param  list<string>  $tokens */
    private function whereAddressOrOwner(Builder $query, array $tokens): void
    {
        foreach ($tokens as $token) {
            $query->where(function (Builder $w) use ($token) {
                $this->whereToken($w, 'o.search_text', $token);
                $w->orWhereExists(fn (Builder $e) => $this->whereToken($this->currentOwnership($e), $this->foldedOwner(), $token));
            });
        }
    }

    private function whereToken(Builder $query, string $expression, string $token): Builder
    {
        if (preg_match('/^\d+$/', $token)) {
            return $query->whereRaw($expression.' ~ ?', ['(^| )'.preg_quote($token, '/').'( |$)']);
        }

        return $query->whereRaw($expression.' like ?', [$this->like($token)]);
    }

    private function currentOwnership(Builder $query): Builder
    {
        return $query->selectRaw('1')->from('ownerships as w')->join('contacts as c', 'c.id', '=', 'w.contact_id')
            ->whereColumn('w.cadastral_unit_id', 'o.cadastral_unit_id')->whereColumn('w.agency_id', 'o.agency_id')
            ->whereNull('w.valid_to')->whereNull('c.removed_at');
    }

    private function ownerName(Builder $query, string $key, string $term): Builder
    {
        $like = $this->like($term);
        $query = $this->currentOwnership($query);
        $column = match ($key) {
            'name' => "coalesce(nullif(c.given_name, ''), nullif(c.company_name, ''), c.display_name)",
            'lastName' => "coalesce(nullif(c.family_name, ''), case when coalesce(c.company_name, '') = '' then c.display_name else '' end)",
            default => "coalesce(c.tax_code, c.vat_number, '')",
        };

        return $query->whereRaw($column.' ilike ?', [$like]);
    }

    private function ownerWithContact(Builder $query): Builder
    {
        return $this->currentOwnership($query)->where(function (Builder $w) {
            $w->whereRaw("c.recapito is not null and btrim(c.recapito) <> ''")
                ->orWhere(fn (Builder $phone) => $phone->whereNull('c.recapito')->whereExists(function (Builder $e) {
                    $e->selectRaw('1')->from('contact_channels as ch')->whereColumn('ch.contact_id', 'c.id')->where('ch.kind', 'phone')->whereRaw("nullif(btrim(ch.value), '') is not null");
                }));
        });
    }

    private function phoneOnUnit(Builder $query, string $digits): Builder
    {
        return $this->currentOwnership($query)->where(function (Builder $w) use ($digits) {
            $w->whereRaw("regexp_replace(coalesce(c.recapito, ''), '\\D', '', 'g') like ?", ['%'.$digits.'%'])
                ->orWhereExists(function (Builder $e) use ($digits) {
                    $e->selectRaw('1')->from('contact_channels as ch')->whereColumn('ch.contact_id', 'c.id')->where('ch.kind', 'phone')
                        ->whereRaw("regexp_replace(coalesce(ch.value, ''), '\\D', '', 'g') like ?", ['%'.$digits.'%']);
                });
        });
    }

    private function multiOwner(Builder $query): Builder
    {
        return $this->currentOwnership($query)->whereRaw($this->globalOwnerCountSql('w.contact_id').' > '.CensusReader::SOGLIA_MULTI_PROPRIETARIO);
    }

    private function upcoming(Builder $query): Builder
    {
        return $query->selectRaw('1')->from('activity_units as au')->join('activities as a', 'a.id', '=', 'au.activity_id')
            ->whereColumn('au.cadastral_unit_id', 'o.cadastral_unit_id')->whereColumn('au.agency_id', 'o.agency_id')
            ->whereNull('a.completed_at')->whereNull('a.cancelled_at')->where('a.status', '!=', 'Annullata')
            ->when($this->m->role === 'scout', fn (Builder $visible) => $visible->where(fn (Builder $who) => $who
                ->where('a.assigned_to_user_id', $this->m->user_id)->orWhere('a.created_by_user_id', $this->m->user_id)
                ->orWhereExists(fn (Builder $participants) => $participants->selectRaw('1')->from('activity_participants as ap')->whereColumn('ap.activity_id', 'a.id')->where('ap.user_id', $this->m->user_id))));
    }

    private function flag(Builder $query, string $term, callable $exists): void
    {
        if ($term === 'Sì') {
            $query->whereExists($exists);

            return;
        }
        if ($term === 'No') {
            $query->whereNotExists($exists);

            return;
        }
        $query->whereRaw('false');
    }

    private function ownerCountSql(): string
    {
        return '(select count(*) from ownerships w join contacts c on c.id = w.contact_id
            where w.cadastral_unit_id = o.cadastral_unit_id and w.agency_id = o.agency_id and w.valid_to is null and c.removed_at is null)';
    }

    private function globalOwnerCountSql(string $contact = 'c.id'): string
    {
        return "(select count(distinct w2.cadastral_unit_id) from ownerships w2
            join agency_unit_observations o2 on o2.cadastral_unit_id = w2.cadastral_unit_id and o2.agency_id = w2.agency_id
            where w2.contact_id = {$contact} and w2.agency_id = {$this->m->agency_id} and w2.valid_to is null
              and o2.state = 'Attivo' and o2.removed is null)";
    }

    private function latestActivitySql(string $column): string
    {
        return "select {$column} from activity_units au join activities a on a.id = au.activity_id
            where au.cadastral_unit_id = o.cadastral_unit_id and au.agency_id = o.agency_id
              and a.completed_at is not null and a.outcome_confirmed_at is not null and a.outcome is not null
              and a.cancelled_at is null and a.kind <> 'Promemoria'
            order by a.outcome_confirmed_at desc, a.id desc limit 1";
    }

    private function foldedContact(): string
    {
        return "translate(lower(coalesce(c.display_name,'') || ' ' || coalesce(c.tax_code,'') || ' ' || coalesce(c.vat_number,'') || ' ' || coalesce(c.given_name,'') || ' ' || coalesce(c.family_name,'') || ' ' || coalesce(c.company_name,'')), 'àáèéìíòóùúâêîôûäëïöü', 'aaeeiioouuaeiouaeiou')";
    }

    private function foldedOwner(): string
    {
        return $this->foldedContact();
    }

    /** @param  array<string,mixed>  $q
     * @return ?array<string,mixed> */
    private function cursor(array $q, string $order, string $outcome): ?array
    {
        $cursor = $q['after'] ?? null;
        if (! is_array($cursor) || ! array_key_exists('key', $cursor) || ($cursor['order'] ?? null) !== $order) {
            return null;
        }
        if ($order === 'Esito' && ($cursor['outcome'] ?? null) !== $outcome) {
            return null;
        }

        return $cursor;
    }

    /** @return array<string,mixed> */
    private function cursorFrom(object $row, string $order, string $outcome): array
    {
        return [
            'order' => $order,
            'outcome' => $outcome,
            'at' => $row->last_outcome_at === null || $row->last_outcome_at === '' ? null : (string) $row->last_outcome_at,
            'key' => (string) $row->archive_sort,
            'group' => (int) ($row->sort_group ?? $this->group($row, $outcome)),
        ];
    }

    /** @param  list<object>  $terrain */
    private function merge(Collection $units, array $terrain, string $order, string $outcome): Collection
    {
        $rows = [...$units->all(), ...$terrain];
        usort($rows, fn (object $a, object $b) => $this->compareRows($a, $b, $order, $outcome));

        return collect($rows);
    }

    /** @param  array<string,mixed>  $cursor */
    private function isAfter(object $row, array $cursor, string $order, string $outcome): bool
    {
        $mark = (object) ['last_outcome_at' => $cursor['at'] ?? null, 'archive_sort' => (string) ($cursor['key'] ?? ''), 'sort_group' => (int) ($cursor['group'] ?? 0), 'last_outcome' => null];

        return $this->compareRows($mark, $row, $order, $outcome) < 0;
    }

    private function compareRows(object $a, object $b, string $order, string $outcome): int
    {
        if ($order === 'Esito') {
            $group = $this->group($a, $outcome) <=> $this->group($b, $outcome);
            if ($group !== 0) {
                return $group;
            }
        }
        $aNull = $a->last_outcome_at === null || $a->last_outcome_at === '';
        $bNull = $b->last_outcome_at === null || $b->last_outcome_at === '';
        if ($aNull !== $bNull) {
            return ($order === 'Ultima attività' ? $aNull : ! $aNull) ? 1 : -1;
        }
        if (! $aNull && (string) $a->last_outcome_at !== (string) $b->last_outcome_at) {
            return $order === 'Ultima attività'
                ? strcmp((string) $b->last_outcome_at, (string) $a->last_outcome_at)
                : strcmp((string) $a->last_outcome_at, (string) $b->last_outcome_at);
        }

        return strcmp((string) $a->archive_sort, (string) $b->archive_sort);
    }

    private function group(object $row, string $outcome): int
    {
        return (($row->last_outcome ?? 'Nessun esito') === ($outcome !== '' ? $outcome : 'Nessun esito')) ? 0 : 1;
    }

    private function categoryTerm(string $search): ?string
    {
        $search = trim($search);

        return preg_match('/^[A-F]\s*\/\s*\d{1,2}$/i', $search) ? strtoupper((string) preg_replace('/\s/', '', $search)) : null;
    }

    /** @return list<string> */
    private function tokens(string $search): array
    {
        return array_values(array_filter(explode(' ', SisterText::fold($search)), fn (string $token) => $token !== ''));
    }

    private function isPhone(string $search): bool
    {
        return (bool) preg_match('/^[+\d\s()-]+$/', trim($search)) && strlen($this->phoneDigits($search)) >= 6;
    }

    private function phoneDigits(string $search): string
    {
        return (string) preg_replace('/^(?:0039|39)(?=\d{9,11}$)/', '', (string) preg_replace('/\D/', '', $search));
    }

    private function like(string $term): string
    {
        return '%'.addcslashes($term, '\\%_').'%';
    }
}
