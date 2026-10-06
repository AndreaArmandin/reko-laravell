<?php

namespace App\Trova;

/**
 * Port of Trova lib/crm/housing-v4.ts (REKO reference v4). One call = one parcel.
 *
 * Every home (A/1–A/9) of a parcel becomes CASA INDIPENDENTE, APPARTAMENTO or
 * DA VERIFICARE, comparing its floors with the other homes at the same civic
 * number. Notes and thresholds are the original ones, word for word.
 */
final class HousingV4
{
    public const INDEPENDENT = 'CASA INDIPENDENTE';

    public const APARTMENT = 'APPARTAMENTO';

    public const UNKNOWN = 'DA VERIFICARE';

    /** Search contract of 23/09/2026: vertical dwelling from T, or from 1 above a non-A ground floor. */
    public const INDEPENDENT_MIN_LEVELS = 2;

    public const INDEPENDENT_MAX_BASE = 1;

    /** Count the complete parcel before applying size, floor or category filters. */
    public const APARTMENT_COUNT_CATEGORIES = ['A/1', 'A/2', 'A/3', 'A/4', 'A/5', 'A/7'];

    public const APARTMENT_EXCLUDED_CATEGORIES = ['A/8', 'A/9', 'A/10'];

    /** Equivalence confirmed by the client on 23/09/2026. Never infer B = BIS elsewhere. */
    private const CIVIC_ALIASES = [
        ['code' => 'D205', 'section' => '', 'sheet' => '90', 'parcel' => '1190', 'via' => 'VIALE DEGLI ANGELI', 'from' => '36B', 'to' => '36BIS'],
    ];

    /**
     * Split a SISTER address: street, civic, staircase and the final labelled floor.
     *
     * @return array{via: string, civico: string, scala: string, piano: string}
     */
    public static function address(string $address): array
    {
        $address = (string) preg_replace('/\s/', ' ', $address);
        preg_match_all('/\s+piano\s+/i', $address, $floors, PREG_OFFSET_CAPTURE);
        $floor = $floors[0] !== [] ? end($floors[0]) : null;
        $head = explode(';', substr($address, 0, $floor[1] ?? strlen($address)))[0];

        preg_match_all('/\s+(n\.|scala\s|interno\s)/i', $head, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $number = null;
        foreach ($all as $m) {
            if (strtolower($m[1][0]) === 'n.') {
                $number = $m;
                break;
            }
        }
        $markers = $number === null ? $all : array_values(array_filter($all, fn ($m) => $m[0][1] >= $number[0][1]));

        $fields = [
            'via' => trim(substr($head, 0, $markers[0][0][1] ?? strlen($head))),
            'civico' => '',
            'scala' => '',
            'piano' => $floor !== null ? trim(substr($address, $floor[1] + strlen($floor[0]))) : '',
        ];
        foreach ($markers as $i => $m) {
            $name = strtolower(trim($m[1][0]));
            $field = $name === 'n.' ? 'civico' : $name;
            if (in_array($field, ['civico', 'scala'], true)) {
                $start = $m[0][1] + strlen($m[0][0]);
                $end = $markers[$i + 1][0][1] ?? strlen($head);
                $fields[$field] = trim(substr($head, $start, $end - $start));
            }
        }

        return $fields;
    }

    /**
     * Floor levels: "T-1" is ground and first floor, "S1" the first basement.
     * Unreadable text returns null; no floor is ever invented.
     *
     * @return non-empty-list<int>|null
     */
    public static function levels(?string $piano): ?array
    {
        if ($piano === null) {
            return null;
        }
        $p = mb_strtoupper(trim($piano));
        if (in_array($p, ['', 'NON INDICATO', 'ND', '-', '—'], true)) {
            return null;
        }

        $levels = [];
        foreach (preg_split('/[\s·,;\/\-]+/u', $p) ?: [] as $t) {
            if ($t === '' || $t === 'E') {
                continue;
            }
            if (in_array($t, ['T', 'PT', 'R', 'PR', 'RIALZATO'], true)) {
                $levels[0] = 0;
            } elseif (preg_match('/^S(\d*)$/', $t, $m)) {
                $n = -(int) ($m[1] !== '' ? $m[1] : 1);
                $levels[$n] = $n;
            } elseif (preg_match('/^P?(\d+)$/', $t, $m)) {
                $levels[(int) $m[1]] = (int) $m[1];
            } else {
                return null;
            }
        }
        if ($levels === []) {
            return null;
        }
        sort($levels);

        return $levels;
    }

    /**
     * @return array{livelli: list<int>, blocco: list<int>, staccati: list<int>, interrati: list<int>}|null
     */
    public static function geometry(?string $piano): ?array
    {
        $livelli = self::levels($piano);
        if ($livelli === null) {
            return null;
        }
        $outside = array_values(array_filter($livelli, fn ($l) => $l >= 0));
        $ref = $outside !== [] ? $outside : $livelli;
        $blocco = [$ref[0]];
        foreach (array_slice($ref, 1) as $level) {
            if ($level !== end($blocco) + 1) {
                break;
            }
            $blocco[] = $level;
        }
        $top = end($blocco);

        return [
            'livelli' => $livelli,
            'blocco' => $blocco,
            'staccati' => array_values(array_filter($ref, fn ($l) => $l > $top)),
            'interrati' => $outside !== [] ? array_values(array_filter($livelli, fn ($l) => $l < 0)) : [],
        ];
    }

    public static function civic(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === 0) {
            return null;
        }
        $c = mb_strtoupper(trim((string) $value));
        if (in_array($c, ['SNC', 'S.N.C.', 'SN'], true)) {
            return null;
        }
        $c = (string) preg_replace('/[\s\/\-.]/', '', $c);

        return $c !== '' ? $c : null;
    }

    /**
     * @param  list<array{key?: mixed, sub: string, categoria: string, address: string}>  $units  all eligible units of ONE parcel
     * @param  array{code: string, section: string, sheet: string, parcel: string}|null  $ref
     * @return array{units: array<int, array{esito: string, conf: string, note: list<string>, livelli_txt: ?string, base: ?int, top: ?int, vertical: bool}>, context: string}
     *                                                                                                                                                                        keyed like $units, homes only
     */
    public static function classify(array $units, ?array $ref = null): array
    {
        $all = [];
        foreach ($units as $i => $u) {
            $a = self::address($u['address']);
            $all[$i] = [
                'sub' => (string) $u['sub'],
                'cat' => mb_strtoupper(trim($u['categoria'])),
                'via' => mb_strtoupper(trim($a['via'])),
                'civico' => self::aliasCivic(self::civic($a['civico']), $a['via'], $ref),
                'scala' => mb_strtoupper(trim($a['scala'])),
                'geo' => self::geometry($a['piano']),
                'note' => [],
                'esito' => null,
                'conf' => null,
                'livelli_txt' => null,
            ];
        }

        $home = fn (array $u) => str_starts_with($u['cat'], 'A/') && $u['cat'] !== 'A/10';
        // A/8 remains a dwelling to classify, but cannot establish an apartment neighbour.
        $evidence = fn (array $u) => $home($u) && $u['cat'] !== 'A/8';
        $building = fn (array $u) => self::civic($u['civico']) !== null
            ? json_encode([$u['via'], self::civic($u['civico']), $u['scala']], JSON_UNESCAPED_UNICODE)
            : null;
        $floorName = fn (int $n) => $n === 0 ? 'T' : ($n < 0 ? 'S'.(-$n) : (string) $n);
        $subs = fn (array $ids) => implode(', ', array_map(fn ($j) => $all[$j]['sub'], $ids));

        $buildings = [];
        $streets = [];
        foreach ($all as $i => $u) {
            if (($k = $building($u)) !== null) {
                $buildings[$k][] = $i;
            }
            $streets[$u['via']][] = $i;
        }
        $homes = array_keys(array_filter($all, $home));

        foreach ($homes as $i) {
            $u = $all[$i];
            $g = $u['geo'];
            if ($g === null) {
                $u['esito'] = self::UNKNOWN;
                $u['conf'] = '-';
                $u['note'][] = 'piano mancante o illeggibile';
                $all[$i] = $u;

                continue;
            }
            $k = $building($u);
            $neighbors = $k !== null ? $buildings[$k] : $streets[$u['via']];
            $others = array_values(array_filter($neighbors, fn ($j) => $j !== $i && $evidence($all[$j]) && $all[$j]['geo'] !== null));
            $base = $g['blocco'][0];
            $top = end($g['blocco']);
            $first = fn ($j) => $all[$j]['geo']['blocco'][0];
            $last = fn ($j) => end($all[$j]['geo']['blocco']);
            $above = array_values(array_filter($others, fn ($j) => $first($j) > $top));
            $below = array_values(array_filter($others, fn ($j) => $last($j) < $base));
            $partial = $k !== null
                ? array_values(array_filter($others, fn ($j) => $first($j) <= $top && $last($j) >= $base && ($first($j) !== $base || $last($j) !== $top)))
                : [];

            if ($g['staccati'] !== []) {
                $u['note'][] = 'locale staccato al piano '.$floorName($g['staccati'][0]).': pertinenza, non conta';
            }
            if ($g['interrati'] !== [] && max($g['interrati']) < -1) {
                $u['note'][] = 'S'.(-max($g['interrati'])).' senza S1: probabile box/cantina in autorimessa comune';
            }

            $departure = 'ok';
            $departureNote = null;
            if ($base < 0) {
                $departure = 'interrata';
            } elseif ($base > 0) {
                $ground = array_filter($neighbors, fn ($j) => $j !== $i && $all[$j]['geo'] !== null && in_array(0, $all[$j]['geo']['livelli'], true));
                $nonA = array_values(array_unique(array_map(fn ($j) => $all[$j]['cat'], array_filter($ground, fn ($j) => ! str_starts_with($all[$j]['cat'], 'A/')))));
                $a10 = array_filter($ground, fn ($j) => $all[$j]['cat'] === 'A/10');
                if ($nonA !== []) {
                    sort($nonA);
                    $departureNote = 'parte dal piano '.$floorName($base).": al T c'e' ".implode(', ', $nonA).' (non abitazione)';
                } else {
                    $departure = 'dubbia';
                    $departureNote = $a10 !== []
                        ? "al T c'e' un A/10: eccezione non applicata, da confermare"
                        : 'parte dal piano '.$floorName($base)." ma al T non risulta nessuna unita'";
                }
            }

            $conflict = count($above) + count($below) > 0;
            if ($departureNote !== null && ! $conflict) {
                $u['note'][] = $departureNote;
            }
            if ($k !== null) {
                if ($conflict) {
                    $u['esito'] = self::APARTMENT;
                    $u['conf'] = 'alta';
                    if ($above !== []) {
                        $u['note'][] = 'abitazione sopra: sub '.$subs($above);
                    }
                    if ($below !== []) {
                        $u['note'][] = 'abitazione sotto: sub '.$subs($below);
                    }
                } elseif ($partial !== []) {
                    $u['esito'] = self::UNKNOWN;
                    $u['conf'] = '-';
                    $u['note'][] = 'stesso civico, piani in parte in comune con sub '.$subs($partial).": affiancate o una sopra l'altra con ingresso al T?";
                } elseif ($departure !== 'ok') {
                    $u['esito'] = self::UNKNOWN;
                    $u['conf'] = '-';
                } else {
                    $u['esito'] = self::INDEPENDENT;
                    $u['conf'] = 'alta';
                }
            } elseif (! $conflict) {
                $u['esito'] = $departure === 'ok' ? self::INDEPENDENT : self::UNKNOWN;
                $u['conf'] = $departure === 'ok' ? 'alta' : '-';
            } elseif ($base > 0) {
                $u['esito'] = self::APARTMENT;
                $u['conf'] = 'media - senza civico';
            } elseif (count($g['blocco']) >= 2) {
                $u['esito'] = self::INDEPENDENT;
                $u['conf'] = 'media - senza civico';
            } else {
                $u['esito'] = self::UNKNOWN;
                $u['conf'] = '-';
                $u['note'][] = "senza civico non si sa se l'alloggio sopra e' nello stesso edificio";
            }

            $n = $base >= 0 ? count($g['blocco']) : 0;
            $u['livelli_txt'] = $n > 0 ? $n.' livell'.($n === 1 ? 'o' : 'i') : 'interrata';
            if ($n > 4 && $u['esito'] === self::INDEPENDENT) {
                $u['note'][] = 'oltre 4 livelli: controllare che non sia un intero stabile';
            }
            $all[$i] = $u;
        }

        // A ground-floor "house" next door may be the ground floor of a building whose homes start at 1.
        $numeric = fn (string $c) => preg_match('/^\d+/', $c, $m) ? (int) $m[0] : null;
        foreach ($buildings as $k => $group) {
            $upper = array_filter($group, fn ($j) => $evidence($all[$j]) && $all[$j]['geo'] !== null && $all[$j]['geo']['blocco'][0] > 0);
            if ($upper === [] || array_filter($group, fn ($j) => $all[$j]['geo'] !== null && in_array(0, $all[$j]['geo']['livelli'], true)) !== []) {
                continue;
            }
            [$via, $civic] = json_decode((string) $k, true);
            $n = $numeric($civic);
            foreach ($buildings as $k2 => $group2) {
                [$via2, $civic2] = json_decode((string) $k2, true);
                $n2 = $numeric($civic2);
                if ($k2 === $k || $via2 !== $via || $n === null || $n2 === null || ! in_array(abs($n - $n2), [0, 2], true)) {
                    continue;
                }
                $ground = array_values(array_filter($group2, fn ($j) => $home($all[$j]) && $all[$j]['esito'] === self::INDEPENDENT
                    && count($all[$j]['geo']['blocco']) === 1 && $all[$j]['geo']['blocco'][0] === 0));
                foreach ($ground as $j) {
                    $v = $all[$j];
                    $v['esito'] = self::UNKNOWN;
                    $v['conf'] = '-';
                    $v['note'][] = "possibile piano terra dell'edificio al civico {$civic} (li' alloggi dal piano 1 senza nulla al T)";
                    $all[$j] = $v;
                }
                if ($ground !== []) {
                    foreach ($upper as $j) {
                        $v = $all[$j];
                        $v['note'][] = "piano terra forse al civico {$civic2} (sub ".$subs($ground).')';
                        $all[$j] = $v;
                    }
                }
            }
        }

        $result = [];
        foreach ($homes as $i) {
            $g = $all[$i]['geo'];
            $result[$i] = [
                'esito' => (string) $all[$i]['esito'],
                'conf' => (string) $all[$i]['conf'],
                'note' => $all[$i]['note'],
                'livelli_txt' => $all[$i]['livelli_txt'],
                'base' => $g['blocco'][0] ?? null,
                'top' => $g !== null ? $g['blocco'][count($g['blocco']) - 1] : null,
                'vertical' => self::isVerticalIndependent($i, $all, $evidence),
            ];
        }

        $ci = count(array_filter($result, fn ($u) => $u['esito'] === self::INDEPENDENT));
        $ap = count(array_filter($result, fn ($u) => $u['esito'] === self::APARTMENT));
        $ciText = $ci.' '.($ci === 1 ? 'casa indipendente' : 'case indipendenti');
        $apText = $ap.' '.($ap === 1 ? 'appartamento' : 'appartamenti');

        return [
            'units' => $result,
            'context' => $ci && $ap ? "MISTO: {$ciText} + {$apText}" : ($ci ? "SOLO CASE INDIPENDENTI ({$ciText})" : ($ap ? 'PLURIFAMILIARE' : 'CONFIGURAZIONE NON DETERMINATA')),
        ];
    }

    /**
     * Apartment search needs at least two distinct homes in the whole parcel.
     *
     * @param  iterable<array{sub: string, categoria: string}>  $units
     */
    public static function apartmentParcel(iterable $units): bool
    {
        $subs = [];
        foreach ($units as $u) {
            $sub = (string) preg_replace('/^0+(?=\d)/', '', trim((string) $u['sub']));
            if ($sub !== '' && in_array(mb_strtoupper(trim($u['categoria'])), self::APARTMENT_COUNT_CATEGORIES, true)) {
                $subs[$sub] = true;
            }
        }

        return count($subs) >= 2;
    }

    /**
     * "4 appartamenti · 1 DA VERIFICARE"
     *
     * @param  iterable<string>  $outcomes
     */
    public static function summary(iterable $outcomes): string
    {
        $count = [self::INDEPENDENT => 0, self::APARTMENT => 0, self::UNKNOWN => 0];
        foreach ($outcomes as $esito) {
            if (isset($count[$esito])) {
                $count[$esito]++;
            }
        }
        $parts = [];
        foreach ($count as $esito => $n) {
            if ($n > 0) {
                $parts[] = $n.' '.match ($esito) {
                    self::INDEPENDENT => $n === 1 ? 'casa indipendente' : 'case indipendenti',
                    self::APARTMENT => $n === 1 ? 'appartamento' : 'appartamenti',
                    default => 'DA VERIFICARE',
                };
            }
        }

        return implode(' · ', $parts);
    }

    /**
     * @param  array<int, array<string, mixed>>  $all
     */
    private static function isVerticalIndependent(int $i, array $all, \Closure $evidence): bool
    {
        $u = $all[$i];
        $g = $u['geo'];
        if ($u['esito'] !== self::INDEPENDENT || $g === null || count($g['blocco']) < self::INDEPENDENT_MIN_LEVELS
            || $g['blocco'][0] < 0 || $g['blocco'][0] > self::INDEPENDENT_MAX_BASE) {
            return false;
        }
        $civic = self::civic($u['civico']);
        $top = end($g['blocco']);
        foreach ($all as $j => $v) {
            // Context is the parcel; staircase labels do not hide homes above.
            if ($j !== $i && $evidence($v) && $v['geo'] !== null && $v['via'] === $u['via']
                && ($civic === null || self::civic($v['civico']) === $civic)
                && (end($v['geo']['blocco']) > $top || $v['geo']['blocco'][0] < $g['blocco'][0])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array{code: string, section: string, sheet: string, parcel: string}|null  $ref
     */
    private static function aliasCivic(?string $civic, string $via, ?array $ref): ?string
    {
        if ($ref === null) {
            return $civic;
        }
        foreach (self::CIVIC_ALIASES as $a) {
            if ($a['code'] === $ref['code'] && $a['section'] === $ref['section'] && $a['sheet'] === $ref['sheet']
                && $a['parcel'] === $ref['parcel'] && $a['via'] === mb_strtoupper(trim($via)) && $a['from'] === $civic) {
                return $a['to'];
            }
        }

        return $civic;
    }
}
