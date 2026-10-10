<?php

namespace App\Gestionale\Scouting;

use App\Gestionale\CommandRejected;
use App\Gestionale\CurrentAgency;
use App\Models\ScoutingZone;
use Illuminate\Support\Facades\DB;

/**
 * Port of lib/crm/census-plan.ts: the plan of parcels a zone has to census (pasted as tab-separated rows),
 * and zoneProgress(): the "particella completa" rule behind the percentage of "Stato del censimento".
 */
final class CensusPlan
{
    public const MAX_ITEMS = 2000;

    public const STATUSES = ['Pianificata', 'In corso', 'Sospesa', 'Chiusa'];

    public const PLACEHOLDER = 'Provincia → Codice Comune → Comune → Fabbricati/Terreni → Sezione (o -) → Foglio → Particella → Quartiere/località';

    /** Legacy drawn zones belonged to Milano; census plans may explicitly span towns. */
    public static function zoneMunicipalities(ScoutingZone $zone): array
    {
        $codes = array_values(array_filter((array) $zone->municipalities, 'is_string'));
        if ($codes !== []) {
            return $codes;
        }
        $plan = (array) $zone->plan;

        return $plan !== [] ? array_values(array_unique(array_map(fn ($p) => (string) ($p['code'] ?? ''), $plan))) : ['F205'];
    }

    /** census-plan.tsx ZoneForm: one parcel per line, columns separated by tabs. */
    public static function parse(string $text): array
    {
        $plan = [];
        foreach (preg_split('/\r?\n/', $text) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = array_map('trim', explode("\t", $line));
            [$province, $code, $municipality, $kind, $section, $sheet, $parcel, $locality] = [...$cells, ...array_fill(0, 8, null)];
            $plan[] = [
                'province' => $province, 'code' => $code, 'municipality' => $municipality, 'kind' => $kind,
                'section' => $section === '-' ? '' : $section, 'sheet' => $sheet, 'parcel' => $parcel, 'locality' => $locality ?? '',
            ];
        }

        return $plan;
    }

    /** The text of the plan, as ZoneForm shows it again when editing. */
    public static function format(array $plan): string
    {
        return implode("\n", array_map(fn ($p) => implode("\t", [$p['province'] ?? '', $p['code'] ?? '', $p['municipality'] ?? '', $p['kind'] ?? '', ($p['section'] ?? '') ?: '-', $p['sheet'] ?? '', $p['parcel'] ?? '', $p['locality'] ?? '']), $plan));
    }

    /**
     * census-sister.ts importContext + census.zone.save row checks.
     *
     * @return array{province: string, code: string, municipality: string, kind: string, section: string, sheet: string, parcel: string, locality: string}
     *
     * @throws CommandRejected
     */
    public static function validateItem(mixed $item): array
    {
        if (! is_array($item) || ! in_array($item['kind'] ?? null, ['Fabbricati', 'Terreni'], true)) {
            throw new CommandRejected('Scegli il Catasto: Fabbricati oppure Terreni.');
        }
        $section = $item['section'] ?? '';
        if (! is_string($item['province'] ?? null) || ! is_string($item['municipality'] ?? null) || ! is_string($item['code'] ?? null) || ! is_string($section)
            || ! preg_match('/^[A-Z]{2}$/i', $item['province']) || trim($item['municipality']) === '' || ! preg_match('/^[A-Z]\d{3}$/i', $item['code']) || mb_strlen($section) > 12) {
            throw new CommandRejected('Completa Provincia, Comune e codice catastale. Controlla gli eventuali metadati della fonte.');
        }
        foreach (['sheet', 'parcel'] as $field) {
            $value = trim((string) ($item[$field] ?? ''));
            if ($value === '' || ! preg_match('/^[\p{L}\d.\/-]+$/u', $value)) {
                throw new CommandRejected('Ogni riga del piano richiede foglio e particella.');
            }
        }

        return [
            'province' => strtoupper($item['province']), 'code' => strtoupper($item['code']), 'municipality' => trim($item['municipality']),
            'kind' => $item['kind'], 'section' => strtoupper(trim($section)), 'sheet' => trim((string) $item['sheet']), 'parcel' => trim((string) $item['parcel']),
            'locality' => trim((string) ($item['locality'] ?? '')),
        ];
    }

    /** census-model.ts keyPart: digits lose leading zeros, anything else is trimmed and uppercased. */
    private static function keyPart(string $value): string
    {
        return preg_match('/^\d+$/', $value) ? (string) preg_replace('/^0+(?=\d)/', '', $value) : strtoupper(trim($value));
    }

    /** planKey(): identity of a parcel inside a plan (agency omitted: it is always the current one). */
    public static function key(array $p): string
    {
        $section = strtoupper(trim((string) ($p['section'] ?? '')));

        return json_encode([strtoupper((string) $p['code']), $p['kind'], $section === '_' ? '' : $section, self::keyPart((string) $p['sheet']), self::keyPart((string) $p['parcel'])], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Key of a stored parcel. */
    private static function parcelKey(object $row): string
    {
        return self::key(['code' => $row->code, 'kind' => $row->cadastral_kind === 'T' ? 'Terreni' : 'Fabbricati', 'section' => $row->section, 'sheet' => $row->sheet, 'parcel' => $row->number]);
    }

    /**
     * Stored parcels (id, release) of the plan items, by planKey.
     *
     * @param  list<array<string, string>>  $plan
     * @return array<string, object>
     */
    public static function parcels(array $plan): array
    {
        if ($plan === []) {
            return [];
        }
        $codes = array_values(array_unique(array_map(fn ($p) => strtoupper((string) $p['code']), $plan)));
        $sheets = array_values(array_unique(array_map(fn ($p) => self::keyPart((string) $p['sheet']), $plan)));
        $part = fn (string $column) => "CASE WHEN {$column} ~ '^[0-9]+$' THEN regexp_replace({$column}, '^0+(?=[0-9])', '') ELSE upper(btrim({$column})) END";
        $rows = DB::table('parcels as p')->join('municipalities as m', 'm.id', '=', 'p.municipality_id')
            ->leftJoin('municipality_catalogs as mc', 'mc.municipality_id', '=', 'm.id')
            ->whereIn('m.cadastral_code', $codes)
            ->whereRaw($part('p.sheet').' IN ('.implode(',', array_fill(0, count($sheets), '?')).')', $sheets)
            ->get(['p.id', 'p.section', 'p.sheet', 'p.number', 'p.cadastral_kind', 'm.cadastral_code as code', 'mc.catalog_release_id as release_id']);
        $keys = array_fill_keys(array_map([self::class, 'key'], $plan), true);
        $found = [];
        foreach ($rows as $row) {
            if (isset($keys[$key = self::parcelKey($row)])) {
                $found[$key] = $row;
            }
        }

        return $found;
    }

    /**
     * zoneProgress(): per plan item the status of the census, plus the totals shown in "Stato del censimento".
     * "Completo" means: import verified, owners and contacts present on the active units. It does not certify
     * that owners are interested or that the territory outside the plan is censused.
     *
     * @param  list<array<string, string>>|null  $plan  plan to evaluate instead of the stored one (closing a zone)
     */
    public static function progress(ScoutingZone $zone, ?array $plan = null): array
    {
        $plan ??= (array) $zone->plan;
        $agencyId = (int) ($zone->agency_id ?? app(CurrentAgency::class)->id());
        $batches = ! $zone->exists ? collect() : DB::table('census_batches')->where('agency_id', $agencyId)->where('scouting_zone_id', $zone->id)->orderBy('id')->get();
        $batches = $batches->map(fn ($b) => ['id' => $b->id, 'at' => $b->created_at, 'summary' => json_decode((string) $b->summary, true) ?: [], 'issues' => json_decode((string) $b->issues, true) ?: []])->all();

        $pending = [];
        foreach ($batches as $batch) {
            foreach ((array) ($batch['summary']['acceptedKeys'] ?? []) as $key) {
                unset($pending[$key]);
            }
            foreach ((array) $batch['issues'] as $i => $issue) {
                $pending[$issue['unitKey'] ?? (($issue['key'] ?? '').':'.($issue['line'] ?? $i))] = $issue;
            }
        }

        $parcels = self::parcels($plan);
        $facts = self::facts($agencyId, $parcels);
        $rows = [];
        foreach ($plan as $item) {
            $key = self::key($item);
            $parcel = $parcels[$key] ?? null;
            $fact = $parcel ? $facts[$parcel->id] : ['active' => 0, 'suppressed' => 0, 'withoutOwner' => 0, 'withoutContact' => 0, 'owners' => [], 'removed' => false];
            $issues = array_values(array_filter($pending, fn ($i) => ($i['key'] ?? null) === $key));
            $inBatch = collect($batches)->contains(fn ($b) => in_array($key, (array) ($b['summary']['keys'] ?? []), true));
            $imported = $inBatch || $fact['owners'] !== [];
            $status = match (true) {
                collect($issues)->contains(fn ($i) => ($i['status'] ?? null) === 'Conflitto') => 'In conflitto',
                $issues !== [] => 'Da verificare',
                ! $imported => 'Da importare',
                $fact['active'] === 0 && ($fact['removed'] || $fact['suppressed'] > 0) => 'Soppresso',
                $fact['withoutOwner'] > 0 => 'Proprietario assente',
                $fact['withoutContact'] > 0 => 'Recapito assente',
                $fact['active'] > 0 => 'Completo',
                default => 'Importato',
            };
            $rows[] = ['item' => $item, 'key' => $key, 'parcelId' => $parcel?->id, 'active' => $fact['active'], 'suppressed' => $fact['suppressed'],
                'withoutOwner' => $fact['withoutOwner'], 'withoutContact' => $fact['withoutContact'], 'status' => $status, 'imported' => $imported];
        }

        $complete = count(array_filter($rows, fn ($r) => in_array($r['status'], ['Completo', 'Soppresso'], true)));
        $owners = [];
        foreach ($rows as $r) {
            if ($r['parcelId'] !== null) {
                foreach ($facts[$r['parcelId']]['owners'] as $contact => $hasContact) {
                    $owners[$contact] = $hasContact;
                }
            }
        }
        $found = array_filter($rows, fn ($r) => $r['parcelId'] !== null);
        $sum = fn (string $field) => array_sum(array_map(fn ($b) => (int) ($b['summary'][$field] ?? 0), $batches));
        $lastBatch = $batches === [] ? null : end($batches);

        return [
            'rows' => $rows, 'batches' => $batches, 'pending' => array_values($pending), 'planned' => count($rows), 'complete' => $complete,
            'percent' => $rows === [] ? 0 : (int) round(100 * $complete / count($rows)),
            'active' => array_sum(array_column($rows, 'active')), 'suppressed' => array_sum(array_column($rows, 'suppressed')),
            'withoutOwner' => array_sum(array_column($rows, 'withoutOwner')),
            'withoutContact' => count(array_filter($owners, fn ($hasContact) => ! $hasContact)),
            'municipalities' => array_values(array_unique(array_map(fn ($r) => $r['item']['municipality'], $found))),
            'sheets' => array_values(array_unique(array_map(fn ($r) => $r['item']['code'].' / '.$r['item']['sheet'], $found))),
            'parcels' => count($found),
            'incomplete' => count(array_filter($pending, fn ($i) => ($i['status'] ?? null) !== 'Conflitto')),
            'conflicts' => count(array_filter($pending, fn ($i) => ($i['status'] ?? null) === 'Conflitto')),
            'duplicates' => $sum('duplicates'),
            'lastCheck' => $lastBatch ? (string) $lastBatch['at'] : '',
        ];
    }

    /**
     * Active/suppressed units of the active catalog edition and the current owners of the active units.
     *
     * @param  array<string, object>  $parcels
     * @return array<int, array{active: int, suppressed: int, withoutOwner: int, withoutContact: int, owners: array<int, bool>, removed: bool}>
     */
    private static function facts(int $agencyId, array $parcels): array
    {
        $facts = [];
        foreach ($parcels as $parcel) {
            $facts[$parcel->id] = ['active' => 0, 'suppressed' => 0, 'withoutOwner' => 0, 'withoutContact' => 0, 'owners' => [], 'removed' => false];
        }
        if ($parcels === []) {
            return $facts;
        }
        $ids = array_map(fn ($p) => (int) $p->id, array_values($parcels));
        $in = implode(',', $ids);
        $units = DB::table('cadastral_units as cu')
            ->join('parcels as p', 'p.id', '=', 'cu.parcel_id')
            ->join('municipality_catalogs as mc', 'mc.municipality_id', '=', 'p.municipality_id')
            ->join('cadastral_unit_versions as v', fn ($j) => $j->on('v.cadastral_unit_id', '=', 'cu.id')->on('v.catalog_release_id', '=', 'mc.catalog_release_id'))
            ->whereIn('cu.parcel_id', $ids)->get(['cu.id', 'cu.parcel_id', 'v.status', 'v.status_reason']);
        $active = [];
        foreach ($units as $unit) {
            if ($unit->status === 'eligible') {
                $facts[$unit->parcel_id]['active']++;
                $active[$unit->id] = $unit->parcel_id;
            } elseif (stripos((string) $unit->status_reason, 'Soppressione') === 0) {
                $facts[$unit->parcel_id]['suppressed']++;
            }
        }

        $owned = [];
        $contacts = DB::table('ownerships as o')->join('contacts as c', 'c.id', '=', 'o.contact_id')
            ->where('o.agency_id', $agencyId)->whereNull('o.valid_to')->whereNull('c.removed_at')
            ->whereRaw("(o.parcel_id IN ({$in}) OR o.cadastral_unit_id IN (SELECT id FROM cadastral_units WHERE parcel_id IN ({$in})))")
            ->get(['o.contact_id', 'o.parcel_id', 'o.cadastral_unit_id']);
        $withChannel = DB::table('contact_channels')->where('agency_id', $agencyId)->whereIn('contact_id', $contacts->pluck('contact_id')->unique())->pluck('contact_id')->flip();
        $unitsByParcel = [];
        foreach ($active as $unitId => $parcelId) {
            $unitsByParcel[$parcelId][] = $unitId;
        }
        foreach ($contacts as $row) {
            $targets = $row->cadastral_unit_id !== null ? (isset($active[$row->cadastral_unit_id]) ? [$row->cadastral_unit_id] : []) : ($unitsByParcel[$row->parcel_id] ?? []);
            $parcelId = $row->cadastral_unit_id !== null ? ($active[$row->cadastral_unit_id] ?? null) : $row->parcel_id;
            if ($targets === [] || $parcelId === null) {
                continue;
            }
            foreach ($targets as $unitId) {
                $owned[$unitId] = true;
            }
            $facts[$parcelId]['owners'][$row->contact_id] = isset($withChannel[$row->contact_id]);
        }
        foreach ($facts as $parcelId => &$fact) {
            $fact['withoutOwner'] = count(array_filter($unitsByParcel[$parcelId] ?? [], fn ($unitId) => ! isset($owned[$unitId])));
            $fact['withoutContact'] = count(array_filter($fact['owners'], fn ($has) => ! $has));
        }
        unset($fact);

        return $facts;
    }
}
