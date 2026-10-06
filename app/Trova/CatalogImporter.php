<?php

namespace App\Trova;

use App\Models\CatalogRelease;
use App\Models\ImportIssue;
use App\Models\ImportRun;
use App\Models\Municipality;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Loads a complete SISTER export (fabbricati) of one Comune as a new catalogue edition.
 *
 * The edition starts as a "draft": nothing changes for the users until it is published, and a draft can be
 * discarded. Geometry (parcel outlines) of the previous active edition is copied forward for the parcels that
 * still exist; new parcels get their position from ParcelGeometryImporter.
 */
final class CatalogImporter
{
    public const PARSER = 'sister-fabbricati-1';

    public const CHUNK = 1000;

    public const MAX_ISSUES = 200;

    /**
     * Creates the Comune (if new), a draft edition and a queued run for a file already stored on the local disk.
     *
     * @throws RuntimeException with a message for the admin
     */
    public function prepare(string $code, ?string $name, string $storedPath, string $originalName, ?string $note = null): ImportRun
    {
        $code = strtoupper(trim($code));
        if (! preg_match('/^[A-Z]\d{3}$/', $code)) {
            throw new RuntimeException('Il codice catastale ha una lettera e tre cifre, per esempio D205.');
        }
        $path = Storage::disk('local')->path($storedPath);
        if (! is_file($path)) {
            throw new RuntimeException('File non trovato.');
        }

        return DB::transaction(function () use ($code, $name, $storedPath, $originalName, $note, $path) {
            $municipality = Municipality::query()->where('cadastral_code', $code)->first();
            $created = $municipality === null;
            if ($municipality === null) {
                $name = trim((string) $name);
                if ($name === '') {
                    throw new RuntimeException("Il Comune {$code} non esiste ancora: indica anche il nome.");
                }
                $municipality = Municipality::query()->create(['cadastral_code' => $code, 'name' => mb_substr($name, 0, 255)]);
            }

            if (CatalogRelease::query()->where('municipality_id', $municipality->id)->where('status', 'draft')->exists()) {
                throw new RuntimeException("{$municipality->name} ha già una bozza: pubblicala o scartala prima di caricare un nuovo file.");
            }

            $base = $code.'-'.now()->format('Ymd');
            $releaseCode = $base;
            for ($i = 2; CatalogRelease::query()->where('code', $releaseCode)->exists(); $i++) {
                $releaseCode = "{$base}-{$i}";
            }

            $release = CatalogRelease::query()->create([
                'municipality_id' => $municipality->id,
                'code' => $releaseCode,
                'label' => 'SISTER '.now()->format('d/m/Y'),
                'released_on' => now()->toDateString(),
                'status' => 'draft',
                'notes' => $note !== null && trim($note) !== '' ? trim($note) : null,
            ]);

            return ImportRun::query()->create([
                'municipality_id' => $municipality->id,
                'catalog_release_id' => $release->id,
                'source_name' => 'sister-catalog',
                'original_filename' => $originalName,
                'private_path' => $storedPath,
                'sha256' => hash_file('sha256', $path),
                'parser_version' => self::PARSER,
                'status' => 'queued',
                'summary' => ['municipality_created' => $created],
            ]);
        });
    }

    /** Reads the file and fills the draft edition. Failures are recorded on the run, never thrown. */
    public function process(ImportRun $run): ImportRun
    {
        set_time_limit(0);
        $guard = new ImportGuard($run);

        try {
            $this->run($run);
        } catch (Throwable $e) {
            report($e);
            $run->forceFill(['status' => 'failed', 'finished_at' => now()])->save();
            $this->issue($run, 'error', 'import-failed', 'Import interrotto da un errore. La bozza può essere scartata e il file ricaricato.', null, []);
        }

        $guard->done = true;

        return $run->refresh();
    }

    private function run(ImportRun $run): void
    {
        $release = CatalogRelease::query()->with('municipality')->findOrFail($run->catalog_release_id);
        $municipality = $release->municipality;
        $code = $municipality->cadastral_code;
        $path = Storage::disk('local')->path((string) $run->private_path);

        $run->forceFill(['status' => 'running', 'started_at' => now()])->save();

        $handle = fopen($path, 'rb') ?: throw new RuntimeException('File non leggibile.');
        $summary = $run->summary ?? [];
        $p = new ImportProgress;
        $lineNumber = 0;

        try {
            while (($line = fgets($handle)) !== false) {
                $lineNumber++;
                $line = rtrim($line, "\r\n");
                if (! mb_check_encoding($line, 'UTF-8')) {
                    $line = mb_convert_encoding($line, 'UTF-8', 'Windows-1252');
                }
                if (SisterParser::isNotData($line)) {
                    continue;
                }
                $p->read++;

                $record = SisterParser::parse($line);
                if ($record === null) {
                    $this->reject($run, $p, 'row-unrecognized', 'Riga non riconosciuta.', $lineNumber, $line);

                    continue;
                }
                // As in Trova: a suppressed row or a common good is kept (as excluded) even when its columns are odd
                if ($record->sub !== '' && (! ctype_digit($record->sub) || (int) $record->sub === 0)) {
                    $this->reject($run, $p, 'row-sub-invalid', 'Subalterno non interpretabile.', $lineNumber, $line);

                    continue;
                }
                if ($record->malformed && ! $record->suppressed && ! $record->common) {
                    $this->reject($run, $p, 'row-malformed', 'Colonne non interpretabili.', $lineNumber, $line);

                    continue;
                }

                $key = $record->legacyKey($code);
                if (isset($p->batch[$key])) {
                    $p->repeated++;
                    // A suppression found anywhere wins over an earlier eligible row of the same unit
                    if (($record->suppressed || $record->common) && ! ($p->batch[$key]->suppressed || $p->batch[$key]->common)) {
                        $p->batch[$key] = $record;
                    }

                    continue;
                }
                $p->batch[$key] = $record;
                if (count($p->batch) >= self::CHUNK) {
                    $this->flush($municipality->id, $release->id, $code, $run, $p);
                }
            }
            $this->flush($municipality->id, $release->id, $code, $run, $p);
        } finally {
            fclose($handle);
        }

        [$read, $rejected, $repeated, $inserted] = [$p->read, $p->rejected, $p->repeated, $p->inserted];

        if ($inserted === 0) {
            $run->forceFill(['status' => 'failed', 'rows_read' => $read, 'rows_rejected' => $rejected, 'finished_at' => now()])->save();
            $this->issue($run, 'error', 'no-units', 'Nessuna unità riconosciuta nel file. Controlla che sia un export SISTER dei fabbricati.', null, []);

            return;
        }

        if ($repeated > 0) {
            $this->issue($run, 'warning', 'repeated-rows', "{$repeated} righe ripetute: è stata tenuta la prima.", null, []);
        }

        $previousId = DB::table('municipality_catalogs')->where('municipality_id', $municipality->id)->value('catalog_release_id');
        $previousId = $previousId !== null && (int) $previousId !== $release->id ? (int) $previousId : null;
        $diff = null;
        if ($previousId !== null) {
            $this->copyGeometry($previousId, $release->id);
            $diff = $this->diff($previousId, $release->id);
            if ($diff['removed'] > 0 && $diff['removed'] > 0.2 * max(1, $diff['removed'] + $diff['unchanged'] + $diff['changed'])) {
                $this->issue($run, 'warning', 'many-units-removed', "Rispetto all'edizione attiva mancano {$diff['removed']} unità: controlla che il file sia l'export completo del Comune.", null, []);
            }
        }

        app(UnitFacts::class)->build($release->id);

        $summary['units'] = DB::table('cadastral_unit_versions')->where('catalog_release_id', $release->id)->selectRaw("count(*) as total, count(*) filter (where status = 'eligible') as eligible")->first();
        $summary['groups'] = DB::table('cadastral_unit_versions')->where('catalog_release_id', $release->id)->where('status', 'eligible')
            ->selectRaw("coalesce(search_group, '—') as g, count(*) as n")->groupBy('g')->orderBy('g')->pluck('n', 'g')->all();
        $summary['diff'] = $diff;
        $summary['previous_release'] = $previousId !== null ? DB::table('catalog_releases')->where('id', $previousId)->value('code') : null;
        $summary['repeated'] = $repeated;

        $run->forceFill([
            'status' => 'done',
            'rows_read' => $read,
            'rows_imported' => $inserted,
            'rows_rejected' => $rejected,
            'summary' => $summary,
            'finished_at' => now(),
        ])->save();
    }

    /** Writes the chunk collected so far and saves the progress the admin page shows. */
    private function flush(int $municipalityId, int $releaseId, string $code, ImportRun $run, ImportProgress $p): void
    {
        if ($p->batch === []) {
            return;
        }
        [$written, $skipped] = $this->write($municipalityId, $releaseId, $code, $p->batch, $p->parcelIds);
        $p->inserted += $written;
        $p->repeated += $skipped;
        $p->batch = [];
        $run->forceFill(['rows_read' => $p->read, 'rows_imported' => $p->inserted, 'rows_rejected' => $p->rejected])->save();
    }

    /** A line that cannot be used: counted always, written as an issue for the first ones only. */
    private function reject(ImportRun $run, ImportProgress $p, string $code, string $message, int $lineNumber, string $line): void
    {
        $p->rejected++;
        if ($p->issues++ < self::MAX_ISSUES) {
            $this->issue($run, 'warning', $code, $message, $lineNumber, ['line' => mb_substr($line, 0, 300)]);
        }
    }

    /**
     * Writes one chunk: parcels, units and their versions in this edition.
     * First row wins: an existing version of the same unit in the same edition is never overwritten,
     * except that a suppression or a common good found later marks it as excluded.
     *
     * @param  array<string, SisterRecord>  $batch
     * @param  array<string, int>  $parcelIds  cache section|sheet|number → parcel id
     * @return array{0: int, 1: int} versions written and rows skipped as already present
     */
    private function write(int $municipalityId, int $releaseId, string $code, array $batch, array &$parcelIds): array
    {
        return DB::transaction(function () use ($municipalityId, $releaseId, $code, $batch, &$parcelIds) {
            // 1. parcels (Fabbricati)
            $new = [];
            foreach ($batch as $r) {
                $k = "{$r->section}|{$r->sheet}|{$r->parcel}";
                if (! isset($parcelIds[$k])) {
                    $new[$k] = [$r->section, $r->sheet, $r->parcel];
                }
            }
            if ($new !== []) {
                $values = $bind = [];
                foreach ($new as [$section, $sheet, $number]) {
                    $values[] = "(?, 'F', ?, ?, ?, now(), now())";
                    array_push($bind, $municipalityId, $section, $sheet, $number);
                }
                $rows = DB::select(
                    'INSERT INTO parcels (municipality_id, cadastral_kind, section, sheet, number, created_at, updated_at) VALUES '.implode(',', $values).'
                     ON CONFLICT (municipality_id, cadastral_kind, section, sheet, number) DO UPDATE SET updated_at = now()
                     RETURNING id, section, sheet, number',
                    $bind,
                );
                foreach ($rows as $row) {
                    $parcelIds["{$row->section}|{$row->sheet}|{$row->number}"] = (int) $row->id;
                }
            }

            // 2. units
            $values = $bind = [];
            foreach ($batch as $r) {
                $values[] = '(?, ?, ?, ?, now(), now())';
                array_push($bind, $parcelIds["{$r->section}|{$r->sheet}|{$r->parcel}"], $r->sub !== '' ? $r->sub : null, $r->sourceRef(), $r->legacyKey($code));
            }
            $rows = DB::select(
                'INSERT INTO cadastral_units (parcel_id, subalterno, source_ref, legacy_key, created_at, updated_at) VALUES '.implode(',', $values).'
                 ON CONFLICT (parcel_id, identity_key) DO UPDATE SET updated_at = now(),
                     legacy_key = COALESCE(cadastral_units.legacy_key, EXCLUDED.legacy_key)
                 RETURNING id, parcel_id, identity_key',
                $bind,
            );
            $unitIds = [];
            foreach ($rows as $row) {
                $unitIds["{$row->parcel_id}|{$row->identity_key}"] = (int) $row->id;
            }

            // 3. versions in this edition
            $values = $bind = [];
            foreach ($batch as $r) {
                $unitId = $unitIds[$parcelIds["{$r->section}|{$r->sheet}|{$r->parcel}"].'|'.$r->identityKey()];
                [$toponym, $civic] = $r->street();
                [$status, $reason] = match (true) {
                    $r->suppressed => ['excluded', 'Soppressione presente nelle fonti'],
                    $r->common => ['excluded', 'Bene comune non censibile'],
                    $r->category === '' => ['excluded', 'Categoria mancante'],
                    default => ['eligible', null],
                };
                $values[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, now(), now())';
                array_push(
                    $bind,
                    $unitId, $releaseId, $status, $reason,
                    $r->category !== '' ? $r->category : null,
                    $r->category !== '' ? (Categories::group($r->category) ?: null) : null,
                    $r->class !== '' ? $r->class : null,
                    $r->value, $r->value !== null ? $r->unit : null, $r->rendita,
                    $r->address !== '' ? $r->address : null, $toponym, $civic, $r->floor(),
                    $r->partita !== '' ? $r->partita : null, $r->zone !== '' ? $r->zone : null,
                );
            }
            $rows = DB::select(
                'INSERT INTO cadastral_unit_versions (cadastral_unit_id, catalog_release_id, status, status_reason, category, search_group, class,
                     consistency, consistency_unit, rendita, address_raw, address_toponym, address_number, floor, partita, census_zone, created_at, updated_at)
                 VALUES '.implode(',', $values).'
                 ON CONFLICT (cadastral_unit_id, catalog_release_id) DO UPDATE SET
                     status = CASE WHEN EXCLUDED.status = \'excluded\' THEN \'excluded\' ELSE cadastral_unit_versions.status END,
                     status_reason = CASE WHEN EXCLUDED.status = \'excluded\' THEN EXCLUDED.status_reason ELSE cadastral_unit_versions.status_reason END,
                     updated_at = now()
                 RETURNING (xmax = 0) AS inserted',
                $bind,
            );
            $written = count(array_filter($rows, fn ($row) => $row->inserted === true || $row->inserted === 't'));

            return [$written, count($batch) - $written];
        });
    }

    /** New edition, same Comune: positions and outlines of parcels that still exist are not asked again. */
    private function copyGeometry(int $fromId, int $toId): void
    {
        $inEdition = 'EXISTS (SELECT 1 FROM cadastral_units u JOIN cadastral_unit_versions v ON v.cadastral_unit_id = u.id AND v.catalog_release_id = ? WHERE u.parcel_id = %s)';

        DB::insert(
            'INSERT INTO parcel_search_points (parcel_id, catalog_release_id, source, location, created_at, updated_at)
             SELECT s.parcel_id, ?, s.source, s.location, now(), now() FROM parcel_search_points s
             WHERE s.catalog_release_id = ? AND '.sprintf($inEdition, 's.parcel_id').' ON CONFLICT DO NOTHING',
            [$toId, $fromId, $toId],
        );
        DB::insert(
            'INSERT INTO parcel_versions (parcel_id, catalog_release_id, area_sqm, quality, cadastral_class, income_dominical, income_agrarian, partita, boundary, created_at, updated_at)
             SELECT s.parcel_id, ?, s.area_sqm, s.quality, s.cadastral_class, s.income_dominical, s.income_agrarian, s.partita, s.boundary, now(), now() FROM parcel_versions s
             WHERE s.catalog_release_id = ? AND '.sprintf($inEdition, 's.parcel_id').' ON CONFLICT DO NOTHING',
            [$toId, $fromId, $toId],
        );
        DB::insert(
            'INSERT INTO building_parcel_links (catalog_release_id, building_id, parcel_id, created_at, updated_at)
             SELECT ?, s.building_id, s.parcel_id, now(), now() FROM building_parcel_links s
             WHERE s.catalog_release_id = ? AND '.sprintf($inEdition, 's.parcel_id').' ON CONFLICT DO NOTHING',
            [$toId, $fromId, $toId],
        );
        DB::insert(
            'INSERT INTO building_versions (building_id, catalog_release_id, area_sqm, footprint, created_at, updated_at)
             SELECT s.building_id, ?, s.area_sqm, s.footprint, now(), now() FROM building_versions s
             WHERE s.catalog_release_id = ? AND s.building_id IN (SELECT building_id FROM building_parcel_links WHERE catalog_release_id = ?) ON CONFLICT DO NOTHING',
            [$toId, $fromId, $toId],
        );
    }

    /**
     * What changed compared with the active edition.
     *
     * @return array{added: int, changed: int, unchanged: int, removed: int}
     */
    private function diff(int $previousId, int $newId): array
    {
        $same = 'n.category IS NOT DISTINCT FROM p.category AND n.consistency IS NOT DISTINCT FROM p.consistency
                 AND n.consistency_unit IS NOT DISTINCT FROM p.consistency_unit AND n.address_raw IS NOT DISTINCT FROM p.address_raw
                 AND n.status IS NOT DISTINCT FROM p.status';

        $row = DB::selectOne(
            "SELECT count(*) FILTER (WHERE p.id IS NULL) AS added,
                    count(*) FILTER (WHERE p.id IS NOT NULL AND NOT ({$same})) AS changed,
                    count(*) FILTER (WHERE p.id IS NOT NULL AND ({$same})) AS unchanged
             FROM cadastral_unit_versions n
             LEFT JOIN cadastral_unit_versions p ON p.cadastral_unit_id = n.cadastral_unit_id AND p.catalog_release_id = ?
             WHERE n.catalog_release_id = ?",
            [$previousId, $newId],
        );
        $removed = DB::selectOne(
            'SELECT count(*) AS n FROM cadastral_unit_versions p WHERE p.catalog_release_id = ?
             AND NOT EXISTS (SELECT 1 FROM cadastral_unit_versions n WHERE n.cadastral_unit_id = p.cadastral_unit_id AND n.catalog_release_id = ?)',
            [$previousId, $newId],
        );

        return ['added' => (int) $row->added, 'changed' => (int) $row->changed, 'unchanged' => (int) $row->unchanged, 'removed' => (int) $removed->n];
    }

    /**
     * Parcels of the edition, and how many have a position on the map.
     *
     * @return array{parcels: int, located: int}
     */
    public static function coverage(int $releaseId): array
    {
        $row = DB::selectOne(
            'SELECT count(DISTINCT u.parcel_id) AS parcels, count(DISTINCT s.parcel_id) AS located
             FROM cadastral_unit_versions v JOIN cadastral_units u ON u.id = v.cadastral_unit_id
             LEFT JOIN parcel_search_points s ON s.parcel_id = u.parcel_id AND s.catalog_release_id = v.catalog_release_id
             WHERE v.catalog_release_id = ?',
            [$releaseId],
        );

        return ['parcels' => (int) $row->parcels, 'located' => (int) $row->located];
    }

    /**
     * Makes the draft the edition users search. The previous one stays archived.
     *
     * @throws RuntimeException
     */
    public function publish(CatalogRelease $release): void
    {
        DB::transaction(function () use ($release) {
            $release = CatalogRelease::query()->lockForUpdate()->findOrFail($release->id);
            if ($release->status !== 'draft') {
                throw new RuntimeException('Solo una bozza si può pubblicare.');
            }
            $done = ImportRun::query()->where('catalog_release_id', $release->id)->where('source_name', 'sister-catalog')->where('status', 'done')->exists();
            if (! $done || ! DB::table('cadastral_unit_versions')->where('catalog_release_id', $release->id)->exists()) {
                throw new RuntimeException('La bozza non è pronta: aspetta la fine dell’import.');
            }

            $previousId = DB::table('municipality_catalogs')->where('municipality_id', $release->municipality_id)->value('catalog_release_id');
            if ($previousId !== null) {
                CatalogRelease::query()->whereKey($previousId)->update(['status' => 'archived']);
            }
            DB::table('municipality_catalogs')->updateOrInsert(
                ['municipality_id' => $release->municipality_id],
                ['catalog_release_id' => $release->id, 'activated_at' => now(), 'updated_at' => now(), 'created_at' => now()],
            );
            $release->update(['status' => 'active']);
        });
    }

    /**
     * Throws a draft away, with everything it loaded. A Comune created only by this draft goes too.
     *
     * @throws RuntimeException
     */
    public function discard(CatalogRelease $release): void
    {
        DB::transaction(function () use ($release) {
            $release = CatalogRelease::query()->lockForUpdate()->findOrFail($release->id);
            if ($release->status !== 'draft') {
                throw new RuntimeException('Solo una bozza si può scartare.');
            }
            $id = $release->id;
            $municipalityId = $release->municipality_id;
            $created = ImportRun::query()->where('catalog_release_id', $id)->where('source_name', 'sister-catalog')->get()
                ->contains(fn (ImportRun $r) => ($r->summary['municipality_created'] ?? false) === true);

            foreach (['trova_unit_facts', 'parcel_housing_contexts', 'catalog_exclusions', 'parcel_search_points', 'parcel_versions', 'building_parcel_links', 'building_versions', 'cadastral_unit_versions'] as $table) {
                DB::table($table)->where('catalog_release_id', $id)->delete();
            }

            // Units and parcels that no edition uses any more
            DB::delete('DELETE FROM cadastral_units u WHERE NOT EXISTS (SELECT 1 FROM cadastral_unit_versions v WHERE v.cadastral_unit_id = u.id)
                        AND u.parcel_id IN (SELECT id FROM parcels WHERE municipality_id = ?)', [$municipalityId]);
            DB::delete('DELETE FROM parcels p WHERE p.municipality_id = ? AND NOT EXISTS (SELECT 1 FROM cadastral_units u WHERE u.parcel_id = p.id)
                        AND NOT EXISTS (SELECT 1 FROM parcel_versions x WHERE x.parcel_id = p.id) AND NOT EXISTS (SELECT 1 FROM parcel_search_points x WHERE x.parcel_id = p.id)
                        AND NOT EXISTS (SELECT 1 FROM building_parcel_links x WHERE x.parcel_id = p.id) AND NOT EXISTS (SELECT 1 FROM parcel_housing_contexts x WHERE x.parcel_id = p.id)',
                [$municipalityId]);

            ImportRun::query()->where('catalog_release_id', $id)->get()->each(fn (ImportRun $r) => $r->forceFill(['catalog_release_id' => null, 'status' => $r->status === 'done' ? 'discarded' : $r->status])->save());
            $release->delete();

            if ($created && ! CatalogRelease::query()->where('municipality_id', $municipalityId)->exists() && ! DB::table('parcels')->where('municipality_id', $municipalityId)->exists()) {
                ImportRun::query()->where('municipality_id', $municipalityId)->update(['municipality_id' => null]);
                Municipality::query()->whereKey($municipalityId)->delete();
            }
        });
    }

    /** @param  array<string, mixed>  $payload */
    private function issue(ImportRun $run, string $severity, string $code, string $message, ?int $row, array $payload): void
    {
        ImportIssue::query()->create([
            'import_run_id' => $run->id,
            'severity' => $severity,
            'code' => $code,
            'message' => $message,
            'row_number' => $row,
            'payload' => $payload,
        ]);
    }
}
