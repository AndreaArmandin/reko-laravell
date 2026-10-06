<?php

namespace App\Console\Commands;

use App\Trova\ZoneImporter;
use Illuminate\Console\Command;
use RuntimeException;

class ImportTrovaZones extends Command
{
    protected $signature = 'trova:import-zones
        {file : GeoJSON FeatureCollection con le zone (es. data/municipal-search-zones.generated.json di Trova)}
        {--code= : importa solo le zone di questo codice catastale}
        {--into= : salva le zone in un altro Comune, per esempio uno demo (serve --code se il file ne contiene più di uno)}
        {--replace : prima di importare cancella le zone di ricerca già presenti del Comune}
        {--dry-run : controlla il file senza scrivere nulla}';

    protected $description = 'Importa i poligoni delle zone di ricerca (quartieri, frazioni) da un file GeoJSON';

    public function handle(ZoneImporter $importer): int
    {
        try {
            $run = $importer->import(
                (string) $this->argument('file'),
                $this->option('code') ?: null,
                $this->option('into') ?: null,
                (bool) $this->option('dry-run'),
                (bool) $this->option('replace'),
            );
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Zone lette {$run->rows_read}, importate {$run->rows_imported}, scartate {$run->rows_rejected}.");
        $issues = $run->issues;
        foreach ($issues as $issue) {
            $this->line("  riga {$issue->row_number} [{$issue->severity}] {$issue->message}");
        }
        if ($run->status === 'dry-run') {
            $this->comment('Prova senza scrittura: nessuna modifica al database.');
        }

        return $run->status === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
