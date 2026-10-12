<?php

namespace App\Trova;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use ZipArchive;

/**
 * Reads the ISTAT "Elenco comuni italiani" (XLSX, or a CSV with the same headers) and loads regions, provinces
 * (unità territoriali sovracomunali, with their sigla) and the national list of Comuni. Port of Trova
 * scripts/import-istat-municipalities.py. Idempotent: running it again updates names and codes in place.
 */
final class IstatMunicipalities
{
    public const URL = 'https://www.istat.it/storage/codici-unita-amministrative/Elenco-comuni-italiani.xlsx';

    /** Header fragments of the columns used. */
    private const COLUMNS = [
        'region_code' => 'Codice Regione',
        'region' => 'Denominazione Regione',
        'province_code' => "Codice dell'Unità territoriale sovracomunale",
        'province' => "Denominazione dell'Unità territoriale sovracomunale",
        'sigla' => 'Sigla automobilistica',
        'istat' => 'Codice Comune formato alfanumerico',
        'name' => 'Denominazione in italiano',
        'code' => 'Codice Catastale',
    ];

    /**
     * @return list<array<string, string>> one row per Comune, keys of COLUMNS
     *
     * @throws RuntimeException
     */
    public static function read(string $path): array
    {
        $table = str_ends_with(strtolower($path), '.csv') ? self::csv($path) : self::xlsx($path);

        $headerIndex = null;
        foreach ($table as $i => $row) {
            if (array_filter($row, fn ($v) => str_contains((string) $v, self::COLUMNS['name'])) !== []) {
                $headerIndex = $i;
                break;
            }
        }
        if ($headerIndex === null) {
            throw new RuntimeException('Intestazione ISTAT non trovata (manca la colonna «Denominazione in italiano»).');
        }
        $map = [];
        foreach (self::COLUMNS as $key => $fragment) {
            foreach ($table[$headerIndex] as $col => $header) {
                if (str_contains(preg_replace('/\s+/u', ' ', (string) $header), $fragment)) {
                    $map[$key] = $col;
                    break;
                }
            }
            if (! isset($map[$key])) {
                throw new RuntimeException("Colonna ISTAT mancante: {$fragment}.");
            }
        }

        $out = [];
        foreach (array_slice($table, $headerIndex + 1) as $row) {
            $r = array_map(fn ($col) => trim((string) ($row[$col] ?? '')), $map);
            if (! preg_match('/^[A-Z]\d{3}$/', strtoupper($r['code'])) || $r['name'] === '') {
                continue;
            }
            $r['code'] = strtoupper($r['code']);
            $r['istat'] = str_pad($r['istat'], 6, '0', STR_PAD_LEFT);
            $r['region_code'] = str_pad($r['region_code'], 2, '0', STR_PAD_LEFT);
            $r['province_code'] = str_pad($r['province_code'], 3, '0', STR_PAD_LEFT);
            $r['sigla'] = strtoupper($r['sigla']);
            $out[] = $r;
        }
        if ($out === []) {
            throw new RuntimeException('Nessun Comune trovato nel file.');
        }

        return $out;
    }

    /**
     * Writes everything in one transaction and fills the province of the platform's Comuni that have none.
     *
     * @param  list<array<string, string>>  $rows
     * @return array{regions: int, provinces: int, municipalities: int, backfilled: int}
     */
    public static function load(array $rows): array
    {
        if (count(array_unique(array_column($rows, 'code'))) !== count($rows)) {
            throw new RuntimeException('Codici catastali ripetuti nel file.');
        }

        return DB::transaction(function () use ($rows) {
            $now = now();
            $regions = collect($rows)->unique('region_code')->map(fn ($r) => ['code' => $r['region_code'], 'name' => $r['region'], 'created_at' => $now, 'updated_at' => $now])->values()->all();
            DB::table('territorial_regions')->upsert($regions, ['code'], ['name', 'updated_at']);
            $regionIds = DB::table('territorial_regions')->pluck('id', 'code');

            $provinces = collect($rows)->unique('province_code')->map(fn ($r) => [
                'code' => $r['province_code'], 'abbreviation' => $r['sigla'] !== '' ? $r['sigla'] : null, 'name' => $r['province'],
                'territorial_region_id' => $regionIds[$r['region_code']] ?? null, 'created_at' => $now, 'updated_at' => $now,
            ])->values()->all();
            DB::table('territorial_provinces')->upsert($provinces, ['code'], ['abbreviation', 'name', 'territorial_region_id', 'updated_at']);
            $provinceIds = DB::table('territorial_provinces')->pluck('id', 'code');

            foreach (array_chunk($rows, 1000) as $chunk) {
                DB::table('territorial_municipalities')->upsert(array_map(fn ($r) => [
                    'cadastral_code' => $r['code'], 'istat_code' => $r['istat'], 'name' => $r['name'],
                    'territorial_province_id' => $provinceIds[$r['province_code']] ?? null, 'created_at' => $now, 'updated_at' => $now,
                ], $chunk), ['cadastral_code'], ['istat_code', 'name', 'territorial_province_id', 'updated_at']);
            }

            $backfilled = DB::update(
                'UPDATE municipalities m SET territorial_province_id = t.territorial_province_id,
                     istat_code = COALESCE(m.istat_code, CASE WHEN NOT EXISTS (SELECT 1 FROM municipalities x WHERE x.istat_code = t.istat_code) THEN t.istat_code END),
                     updated_at = now()
                 FROM territorial_municipalities t
                 WHERE t.cadastral_code = m.cadastral_code AND m.territorial_province_id IS NULL AND t.territorial_province_id IS NOT NULL'
            );

            return ['regions' => count($regions), 'provinces' => count($provinces), 'municipalities' => count($rows), 'backfilled' => $backfilled];
        });
    }

    /** @return list<array<string, string>> rows as column letter => value */
    private static function xlsx(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Il file non è un XLSX leggibile.');
        }
        $strings = [];
        if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            foreach (simplexml_load_string($xml)->si as $si) {
                $strings[] = trim(strip_tags($si->asXML()));
            }
        }
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        if ($sheet === false) {
            throw new RuntimeException('Il file XLSX non ha il primo foglio.');
        }

        $rows = [];
        foreach (simplexml_load_string($sheet)->sheetData->row as $row) {
            $values = [];
            foreach ($row->c as $c) {
                $col = preg_replace('/\d+/', '', (string) $c['r']);
                $type = (string) $c['t'];
                $values[$col] = match ($type) {
                    's' => $strings[(int) $c->v] ?? '',
                    'inlineStr' => trim(strip_tags($c->is->asXML())),
                    default => (string) $c->v,
                };
            }
            $rows[] = $values;
        }

        return $rows;
    }

    /** @return list<array<int, string>> */
    private static function csv(string $path): array
    {
        $handle = fopen($path, 'rb') ?: throw new RuntimeException('File non leggibile.');
        $rows = [];
        while (($line = fgets($handle)) !== false) {
            $line = (string) preg_replace('/^\x{FEFF}/u', '', mb_check_encoding($line, 'UTF-8') ? $line : mb_convert_encoding($line, 'UTF-8', 'Windows-1252'));
            $rows[] = str_getcsv(rtrim($line, "\r\n"), ';', '"', '');
        }
        fclose($handle);

        return $rows;
    }
}
