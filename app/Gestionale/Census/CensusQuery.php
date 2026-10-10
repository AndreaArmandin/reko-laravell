<?php

namespace App\Gestionale\Census;

use App\Gestionale\CommandRejected;
use App\Models\AgencyMembership;
use Collator;
use Illuminate\Support\Facades\DB;

/**
 * queryCensus() di census-query.ts: filtri, ricerca libera, ordinamento e pagine dell'Archivio catastale,
 * per Immobili e per Proprietari. Il Comune (o un riferimento preciso) è sempre obbligatorio per non caricare l'intero archivio.
 */
final class CensusQuery
{
    public const OUTCOMES_NEW = ['Interessato', 'Non interessato', 'Informazione', 'Informatore', 'Fissato appuntamento', 'Non risponde', 'Numero sbagliato/inesistente', 'Trovare sul posto', 'Preso incarico', 'Sceso prezzo'];

    public const MODES = ['catastale', 'frazione', 'indirizzo', 'proprietario'];

    /** Filtri rimossi su richiesta del cliente; anche le vecchie chiamate non devono applicarli. */
    public const REMOVED_FILTERS = ['section', 'zone', 'geometry', 'source', 'state', 'operator'];

    public const MODE_FIELDS = [
        'catastale' => ['sheet', 'parcel', 'sub', 'withoutSub', 'category'],
        'frazione' => ['locality'],
        'indirizzo' => ['address', 'civic', 'floor'],
        'proprietario' => ['name', 'lastName', 'cf'],
    ];

    /** censusOutcomes di census-filter-options.ts */
    public static function outcomes(): array
    {
        return array_values(array_unique([...self::OUTCOMES_NEW, 'Nessuna risposta', 'Numero non valido', 'Non è il proprietario', 'Da richiamare', 'Non interessato',
            'Informazione ricevuta', 'Potenziale', 'Appuntamento fissato', 'Appuntamento di acquisizione fissato', 'Incarico acquisito']));
    }

    /** filtersForCensusMode(): i campi delle altre modalità vengono tolti. */
    public static function filtersForMode(array $filters, string $mode): array
    {
        $next = $filters;
        $next['_mode'] = [$mode];
        foreach (self::MODE_FIELDS as $key => $fields) {
            if ($key !== $mode) {
                foreach ($fields as $field) {
                    unset($next[$field]);
                }
            }
        }

        return $next;
    }

    public function __construct(private readonly AgencyMembership $m) {}

    /**
     * @param  array{view?:string,search?:string,filters?:array<string,list<string>>,page?:int,size?:int,order?:string,firstOutcome?:string,parcelId?:?int,ownerId?:?int,unitId?:?int}  $q
     * @return array{page:int,size:int,total:int,items:list<array<string,mixed>>,people:list<array<string,mixed>>,options:array<string,list<string>>}
     */
    public function run(array $q): array
    {
        if (! CensusScope::canUse($this->m)) {
            throw new CommandRejected(CensusScope::NOT_ALLOWED, 403);
        }
        $view = $q['view'] ?? 'Immobili';
        $rawFilters = $q['filters'] ?? [];
        foreach (self::REMOVED_FILTERS as $removed) {
            unset($rawFilters[$removed]);
        }
        $mode = $rawFilters['_mode'][0] ?? null;
        $filters = in_array($mode, self::MODES, true) ? self::filtersForMode($rawFilters, $mode) : $rawFilters;
        $size = max(1, min(100, (int) floor((float) ($q['size'] ?? 20)) ?: 20));
        $page = max(1, (int) floor((float) ($q['page'] ?? 1)) ?: 1);
        $search = (string) ($q['search'] ?? '');
        $q['search'] = $search;

        $reader = new CensusReader($this->m);
        $basicKeys = array_diff(array_keys($filters), ['municipalityCode', '_mode']);
        if ($view !== 'Proprietari' && $search === '' && $basicKeys === []
            && ! empty($filters['municipalityCode'][0]) && empty($q['unitId']) && empty($q['parcelId']) && empty($q['ownerId'])) {
            return $this->runMunicipalityPage($reader, (string) $filters['municipalityCode'][0], $page, $size, $q);
        }

        $focus = ['municipalityCode' => $filters['municipalityCode'][0] ?? null, 'unitId' => $q['unitId'] ?? null,
            'parcelId' => $q['parcelId'] ?? null, 'ownerId' => $q['ownerId'] ?? null];
        $all = $reader->entries($focus)->all();
        $visibleOwnerIds = collect($all)->flatMap(fn ($row) => collect($row['owners'])->pluck('id'))->unique()->values()->all();
        $counts = $reader->ownerCounts($visibleOwnerIds);

        $categoryTerm = preg_match('/^[A-F]\s*\/\s*\d{1,2}$/i', trim($search)) ? strtoupper((string) preg_replace('/\s/', '', $search)) : null;
        $requestedOwner = ! empty($q['ownerId']) ? $this->owner((int) $q['ownerId']) : null;

        $matches = function (array $r, ?array $person = null) use ($filters, $q, $search, $categoryTerm, $requestedOwner, $counts): bool {
            $o = $requestedOwner ? collect($r['owners'])->first(fn ($x) => $x['id'] === $requestedOwner['id']) : null;
            if ((! empty($q['ownerId']) && ! $o) || (! empty($q['parcelId']) && $r['parcelId'] !== (int) $q['parcelId']) || (! empty($q['unitId']) && $r['unitId'] !== (int) $q['unitId'])) {
                return false;
            }
            $u = $r['unit'];
            if (empty($filters['state']) && ! self::active($u)) {
                return false;
            }
            $local = $person ? collect($r['owners'])->first(fn ($x) => $x['id'] === $person['id']) : null;
            $people = $local ? [$local] : ($person ? [$person] : $r['owners']);
            $latest = $local ? collect($r['history'])->first(fn ($h) => $h['ownerId'] === $local['id']) : $r['latest'];
            $c = $r['context'];
            $sub = $u['sub'];
            $values = [
                'municipality' => $c['municipality'], 'municipalityCode' => $c['code'], 'address' => $r['street'] !== '' ? $r['street'] : $r['address'], 'civic' => $r['civic'],
                'locality' => $r['locality'], 'zone' => $u['censusZone'], 'floor' => $u['floor'], 'section' => $c['section'], 'sheet' => $c['sheet'], 'parcel' => $c['parcel'],
                'sub' => $sub, 'category' => $u['category'], 'state' => $u['state'] ?: 'Attivo', 'source' => $u['source'] ?: 'demo',
                'hasOwner' => $r['owners'] ? 'Sì' : 'No', 'hasContact' => collect($people)->contains(fn ($o) => trim(CensusReader::contactText($o)) !== '') ? 'Sì' : 'No',
                'operator' => $r['operator'], 'outcome' => $latest['outcome'] ?? 'Nessun esito', 'never' => $latest ? 'No' : 'Sì', 'upcoming' => $r['upcoming'] ? 'Sì' : 'No',
                'name' => collect($people)->map(fn ($o) => $o['firstName'] ?: ($o['companyName'] ?: $o['name']))->implode(' '),
                'lastName' => collect($people)->map(fn ($o) => $o['lastName'] ?: ($o['companyName'] === '' ? $o['name'] : ''))->implode(' '),
                'cf' => collect($people)->pluck('pid')->implode(' '), 'coownership' => count($r['owners']) > 1 ? 'Sì' : 'No',
                'multiOwner' => collect($people)->contains(fn ($o) => ($counts[$o['id']] ?? 0) > CensusReader::SOGLIA_MULTI_PROPRIETARIO) ? 'Sì' : 'No',
                'geometry' => $r['geographyStatus'] === 'ready' ? 'Sì' : 'No', 'withoutSub' => $sub === '' ? 'Sì' : 'No',
                'commercial' => '', 'activity' => $latest['type'] ?? '', 'date' => isset($latest['at']) ? substr($latest['at'], 0, 10) : '',
            ];
            foreach ($filters as $key => $terms) {
                if (! $terms || in_array($key, ['count', '_mode'], true)) {
                    continue;
                }
                $ok = false;
                foreach ($terms as $t) {
                    $v = $values[$key] ?? '';
                    $ok = $key === 'civic' ? SisterText::normalizeCivic($v) === SisterText::normalizeCivic($t)
                        : (in_array($key, ['address', 'locality', 'name', 'lastName', 'cf', 'commercial'], true) ? mb_stripos($v, $t) !== false : $v === $t);
                    if ($ok) {
                        break;
                    }
                }
                if (! $ok) {
                    return false;
                }
            }
            if ($categoryTerm !== null) {
                return strtoupper((string) preg_replace('/\s/', '', $u['category'])) === $categoryTerm;
            }
            if ($search === '') {
                return true;
            }
            $hay = implode(' ', [$r['address'], $r['parcelAddress'], $r['street'], $r['civic'], $u['category'], $c['municipality'], $c['sheet'], $c['parcel'], $c['sheet'].$c['parcel'],
                self::reference($c, $sub), $sub, ...collect($people)->flatMap(fn ($o) => [$o['name'], $o['pid'], CensusReader::contactText($o)])->all()]);

            return SisterText::matches($hay, $search);
        };

        $selected = array_values(array_filter($all, fn ($r) => $matches($r, $requestedOwner ? collect($r['owners'])->first(fn ($o) => $o['id'] === $requestedOwner['id']) : null)));
        usort($selected, fn ($a, $b) => $this->compareOrder($a['latest'], $b['latest'], $q) ?: self::compareAddress($a, $b));

        $people = [];
        if ($view === 'Proprietari') {
            $byId = [];
            foreach ($all as $r) {
                foreach ($r['owners'] as $o) {
                    $byId[$o['id']] ??= $o;
                }
            }
            if ($requestedOwner) {
                $byId[$requestedOwner['id']] ??= $requestedOwner;
            }
            foreach ($byId as $owner) {
                $matching = array_values(array_filter($all, fn ($r) => collect($r['owners'])->contains(fn ($o) => $o['id'] === $owner['id']) && $matches($r, $owner)));
                $count = $counts[$owner['id']] ?? 0;
                $latest = collect($matching)->flatMap(fn ($row) => collect($row['history'])->filter(fn ($h) => $h['ownerId'] === $owner['id']))->sortByDesc(fn ($h) => $h['at'].str_pad((string) $h['id'], 12, '0', STR_PAD_LEFT))->first();
                $p = ['owner' => $owner, 'count' => $count, 'latest' => $latest, 'multiOwner' => $count > CensusReader::SOGLIA_MULTI_PROPRIETARIO, 'matching' => array_column($matching, 'unitId')];
                if (! empty($q['ownerId']) && $owner['id'] !== (int) $q['ownerId']) {
                    continue;
                }
                if ($categoryTerm !== null && ! $matching) {
                    continue;
                }
                $v = ['name' => $owner['firstName'] ?: ($owner['companyName'] ?: $owner['name']), 'lastName' => $owner['lastName'] ?: ($owner['companyName'] === '' ? $owner['name'] : ''),
                    'cf' => $owner['pid'], 'hasContact' => trim(CensusReader::contactText($owner)) !== '' ? 'Sì' : 'No', 'multiOwner' => $p['multiOwner'] ? 'Sì' : 'No'];
                $skip = false;
                foreach ($v as $k => $value) {
                    if (! empty($filters[$k]) && ! collect($filters[$k])->contains(fn ($t) => in_array($k, ['hasContact', 'multiOwner'], true) ? $value === $t : mb_stripos($value, $t) !== false)) {
                        $skip = true;
                    }
                }
                if ($skip) {
                    continue;
                }
                if (! empty($filters['count']) && ! collect($filters['count'])->contains(fn ($n) => $n === 'Più di uno' ? $count > 1 : $count === (int) $n)) {
                    continue;
                }
                $narrow = array_filter(array_keys($filters), fn ($k) => ! in_array($k, ['count', 'name', 'lastName', 'cf', 'hasContact', 'multiOwner', '_mode'], true) && ! empty($filters[$k])) !== [] || ! empty($q['parcelId']);
                if ($narrow) {
                    if (! $matching) {
                        continue;
                    }
                } elseif ($search !== '' && ! SisterText::matches($owner['name'].' '.$owner['pid'].' '.CensusReader::contactText($owner), $search) && ! $matching) {
                    continue;
                }
                $people[] = $p;
            }
            usort($people, fn ($a, $b) => $this->compareOrder($a['latest'], $b['latest'], $q) ?: self::collate($a['owner']['name'], $b['owner']['name']) ?: $a['owner']['id'] <=> $b['owner']['id']);
        }

        $total = $view === 'Proprietari' ? count($people) : count($selected);
        $offset = ($page - 1) * $size;
        $outcomeOptions = array_values(array_unique(['Nessun esito', ...($view === 'Proprietari'
            ? collect($all)->flatMap(fn ($r) => collect($r['owners'])->map(fn ($o) => collect($r['history'])->first(fn ($h) => $h['ownerId'] === $o['id'])['outcome'] ?? 'Nessun esito'))->all()
            : collect($all)->map(fn ($r) => $r['latest']['outcome'] ?? 'Nessun esito')->all())]));

        return [
            'page' => $page, 'size' => $size, 'total' => $total,
            'items' => $view === 'Proprietari' ? [] : array_slice($selected, $offset, $size),
            'people' => array_slice($people, $offset, $size),
            'options' => ['outcome' => $outcomeOptions],
        ];
    }

    /** Pagina per pagina il caso più comune, prima di idratare i dettagli collegati. */
    private function runMunicipalityPage(CensusReader $reader, string $municipalityCode, int $page, int $size, array $q): array
    {
        $active = CensusScope::units($this->m)->where('mu.cadastral_code', $municipalityCode)
            ->where('o.state', 'Attivo')->whereNull('o.removed');
        $total = (clone $active)->distinct()->count('o.cadastral_unit_id');
        $latest = function (string $column) use ($municipalityCode) {
            return DB::table('activity_units as au')->join('activities as a', 'a.id', '=', 'au.activity_id')
                ->whereColumn('au.agency_id', 'o.agency_id')->whereColumn('au.cadastral_unit_id', 'o.cadastral_unit_id')
                ->whereNotNull('a.completed_at')->whereNotNull('a.outcome_confirmed_at')->whereNotNull('a.outcome')
                ->whereNull('a.cancelled_at')->where('a.kind', '!=', 'Promemoria')
                ->when($this->m->role === 'scout', fn ($query) => $query->where(fn ($visible) => $visible
                    ->where('a.assigned_to_user_id', $this->m->user_id)->orWhere('a.created_by_user_id', $this->m->user_id)
                    ->orWhereExists(fn ($participants) => $participants->selectRaw('1')->from('activity_participants as ap')
                        ->whereColumn('ap.activity_id', 'a.id')->where('ap.user_id', $this->m->user_id))))
                ->orderByDesc('a.outcome_confirmed_at')->orderByDesc('a.id')->limit(1)->select("a.{$column}");
        };
        $rows = clone $active;
        if (($q['order'] ?? 'Da contattare') === 'Ultima attività') {
            $at = $latest('outcome_confirmed_at');
            $rows->orderByRaw('('.$at->toSql().') desc nulls last', $at->getBindings());
        } elseif (($q['order'] ?? '') === 'Esito') {
            $outcome = $latest('outcome');
            $target = (string) ($q['firstOutcome'] ?? '');
            if ($target !== '') {
                $rows->orderByRaw('(case when ('.$outcome->toSql().') = ? then 0 else 1 end) asc', [...$outcome->getBindings(), $target]);
            } else {
                $rows->orderByRaw('('.$outcome->toSql().') asc nulls first', $outcome->getBindings());
            }
            $at = $latest('outcome_confirmed_at');
            $rows->orderByRaw('('.$at->toSql().') asc nulls first', $at->getBindings());
        } else {
            $at = $latest('outcome_confirmed_at');
            $rows->orderByRaw('('.$at->toSql().') asc nulls first', $at->getBindings());
        }
        $rows->orderByRaw("lower(coalesce(nullif(o.address, ''), concat(mu.name, ' · F. ', p.sheet, ' P. ', p.number))) asc")
            ->orderBy('p.sheet')->orderBy('p.number')->orderBy('cu.subalterno')->orderBy('o.cadastral_unit_id');
        $ids = (clone $rows)->offset(($page - 1) * $size)->limit($size)
            ->pluck('o.cadastral_unit_id')->map(fn ($id) => (int) $id)->all();
        $entries = $ids === [] ? collect() : $reader->entries(['municipalityCode' => $municipalityCode, 'unitIds' => $ids])->keyBy('unitId');

        return [
            'page' => $page, 'size' => $size, 'total' => $total,
            'items' => collect($ids)->map(fn ($id) => $entries->get($id))->filter()->values()->all(),
            'people' => [], 'options' => ['outcome' => self::outcomes()],
        ];
    }

    public static function active(array $unit): bool
    {
        return ($unit['state'] ?: 'Attivo') === 'Attivo' && empty($unit['removed']);
    }

    public static function reference(array $c, ?string $sub = null): string
    {
        return ($c['section'] !== '' && $c['section'] !== '_' ? 'Sez. '.$c['section'].' · ' : '').'F. '.$c['sheet'].' · P. '.$c['parcel'].($sub !== null ? ' · Sub. '.($sub !== '' ? $sub : '—') : '');
    }

    /** @return array<string,mixed>|null il proprietario richiesto, anche senza unità. */
    private function owner(int $id): ?array
    {
        $c = DB::table('contacts')->where('agency_id', $this->m->agency_id)->where('id', $id)->whereNull('removed_at')->first();
        if ($c === null || ! CensusScope::ownerVisible($this->m, $id)) {
            return null;
        }
        $phones = DB::table('contact_channels')->where('contact_id', $id)->where('kind', 'phone')->orderByDesc('is_primary')->orderBy('id')->get();

        return CensusReader::owner($c, $phones);
    }

    /** compareContactOrder(): Mai contattati prima, poi le attività meno recenti. */
    private function compareOrder(?array $a, ?array $b, array $q): int
    {
        $first = $a['at'] ?? '';
        $second = $b['at'] ?? '';

        return match ($q['order'] ?? '') {
            'Da contattare' => strcmp($first, $second),
            'Ultima attività' => strcmp($second, $first),
            'Esito' => ! empty($q['firstOutcome'])
                ? (int) (($b['outcome'] ?? 'Nessun esito') === $q['firstOutcome']) - (int) (($a['outcome'] ?? 'Nessun esito') === $q['firstOutcome']) ?: strcmp($first, $second)
                : self::collate($a['outcome'] ?? '', $b['outcome'] ?? ''),
            default => 0,
        };
    }

    private static function compareAddress(array $a, array $b): int
    {
        return self::collate($a['address'], $b['address']) ?: self::collate($a['context']['sheet'], $b['context']['sheet'])
            ?: self::collate($a['context']['parcel'], $b['context']['parcel']) ?: self::collate($a['unit']['sub'], $b['unit']['sub']);
    }

    public static function collate(string $a, string $b): int
    {
        static $collator = null;
        $collator ??= class_exists(Collator::class) ? tap(new Collator('it'), fn ($c) => $c->setAttribute(Collator::NUMERIC_COLLATION, Collator::ON)) : false;

        return $collator ? (int) $collator->compare($a, $b) : strnatcasecmp($a, $b);
    }
}
