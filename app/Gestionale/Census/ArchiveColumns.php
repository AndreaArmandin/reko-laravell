<?php

namespace App\Gestionale\Census;

use Illuminate\Support\Facades\DB;

/** Colonne di ordinamento e ricerca dell'archivio, aggiornate quando cambia la scheda, l'esito o l'intestatario. */
final class ArchiveColumns
{
    /** @param  list<int>  $unitIds */
    public static function sync(int $agencyId, array $unitIds): void
    {
        foreach (array_chunk(self::ids($unitIds), 200) as $chunk) {
            self::syncSort($agencyId, $chunk);
            self::syncText($agencyId, $chunk);
        }
    }

    /** @param  list<int>  $unitIds */
    public static function syncOutcomes(int $agencyId, array $unitIds): void
    {
        foreach (array_chunk(self::ids($unitIds), 200) as $chunk) {
            self::syncSort($agencyId, $chunk);
        }
    }

    public static function syncContact(int $agencyId, int $contactId): void
    {
        $unitIds = DB::table('ownerships')->where('agency_id', $agencyId)->where('contact_id', $contactId)
            ->whereNotNull('cadastral_unit_id')->pluck('cadastral_unit_id')->all();
        self::sync($agencyId, $unitIds);
    }

    public static function backfill(): void
    {
        DB::table('agency_unit_observations')->select('id', 'agency_id', 'cadastral_unit_id')->orderBy('id')
            ->chunkById(200, function ($rows): void {
                $byAgency = [];
                foreach ($rows as $row) {
                    $byAgency[(int) $row->agency_id][] = (int) $row->cadastral_unit_id;
                }
                foreach ($byAgency as $agencyId => $unitIds) {
                    self::sync($agencyId, $unitIds);
                }
            });
    }

    /** @param  list<int>  $unitIds */
    private static function syncSort(int $agencyId, array $unitIds): void
    {
        if ($unitIds === []) {
            return;
        }
        $placeholders = implode(', ', array_fill(0, count($unitIds), '?'));
        $shared = "from activity_units au
            join activities a on a.id = au.activity_id
            where au.cadastral_unit_id = o.cadastral_unit_id and au.agency_id = o.agency_id
              and a.completed_at is not null and a.outcome_confirmed_at is not null and a.outcome is not null
              and a.cancelled_at is null and a.kind <> 'Promemoria'
            order by a.outcome_confirmed_at desc, a.id desc limit 1";
        $address = "lower(coalesce(nullif(btrim(o.address), ''), mu.name || ' · F. ' || p.sheet || ' P. ' || p.number))";
        DB::update(
            "update agency_unit_observations as o
             set municipality_id = p.municipality_id,
                 address_sort = {$address},
                 archive_sort = {$address} || chr(1) || coalesce(p.sheet, '') || chr(1) || coalesce(p.number, '') || chr(1) || coalesce(cu.subalterno, '') || chr(1) || lpad(o.cadastral_unit_id::text, 16, '0'),
                 last_outcome = (select a.outcome {$shared}),
                 last_outcome_at = (select a.outcome_confirmed_at {$shared})
             from cadastral_units as cu
             join parcels as p on p.id = cu.parcel_id
             join municipalities as mu on mu.id = p.municipality_id
             where o.cadastral_unit_id = cu.id and o.agency_id = ? and o.cadastral_unit_id in ({$placeholders})",
            [$agencyId, ...$unitIds],
        );
    }

    /** @param  list<int>  $unitIds */
    private static function syncText(int $agencyId, array $unitIds): void
    {
        $rows = DB::table('agency_unit_observations as o')
            ->join('cadastral_units as cu', 'cu.id', '=', 'o.cadastral_unit_id')
            ->join('parcels as p', 'p.id', '=', 'cu.parcel_id')
            ->join('municipalities as mu', 'mu.id', '=', 'p.municipality_id')
            ->where('o.agency_id', $agencyId)->whereIn('o.cadastral_unit_id', $unitIds)
            ->get(['o.cadastral_unit_id', 'o.address', 'o.street', 'o.civic', 'o.locality', 'o.location_source_address', 'o.category',
                'cu.subalterno', 'p.section', 'p.sheet', 'p.number', 'mu.name as municipality']);
        if ($rows->isEmpty()) {
            return;
        }
        $bindings = [];
        $values = [];
        foreach ($rows as $row) {
            $sub = $row->subalterno === null ? '' : (string) $row->subalterno;
            $parcelAddress = $row->municipality.' · F. '.$row->sheet.' P. '.$row->number;
            $location = CensusReader::location([
                'address' => (string) $row->address, 'street' => $row->street, 'civic' => $row->civic,
                'locality' => (string) $row->locality, 'locationSourceAddress' => $row->location_source_address,
            ], $parcelAddress);
            $display = CensusReader::unitAddress($location);
            $ownAddress = trim($display) !== '';
            $context = ['section' => (string) $row->section, 'sheet' => (string) $row->sheet, 'parcel' => (string) $row->number];
            $haystack = implode(' ', [$display, $ownAddress ? '' : $parcelAddress, $ownAddress ? '' : $location['street'], $location['civic'],
                (string) $row->category, (string) $row->municipality, (string) $row->sheet, (string) $row->number, $row->sheet.$row->number,
                CensusQuery::reference($context, $sub), $sub]);
            $values[] = '(?, ?, ?, ?)';
            $bindings[] = (int) $row->cadastral_unit_id;
            $bindings[] = SisterText::fold($haystack);
            $bindings[] = ($location['street'] ?? '') !== '' ? $location['street'] : $display;
            $bindings[] = SisterText::normalizeCivic((string) $location['civic']);
        }
        $bindings[] = $agencyId;
        DB::update(
            'update agency_unit_observations as o
             set search_text = v.search_text, filter_address = v.filter_address, filter_civic = v.filter_civic
             from (values '.implode(', ', $values).') as v(unit_id, search_text, filter_address, filter_civic)
             where o.agency_id = ? and o.cadastral_unit_id = v.unit_id::bigint',
            $bindings,
        );
    }

    /** @param  list<int|string>  $unitIds
     * @return list<int> */
    private static function ids(array $unitIds): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $unitIds))));
    }
}
