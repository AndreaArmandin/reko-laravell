<?php

namespace App\Console\Commands;

use App\Models\CatalogRelease;
use App\Models\ImportRun;
use App\Trova\CatalogImporter;
use Illuminate\Console\Command;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class ImportTrovaCatalog extends Command
{
    protected $signature = 'trova:import-catalog
        {code : codice catastale del Comune, es. D205}
        {file : export SISTER (fabbricati) completo del Comune}
        {--name= : nome del Comune, obbligatorio se non esiste ancora}
        {--note= : nota sull\'edizione}
        {--publish : pubblica subito l\'edizione a import finito}';

    protected $description = 'Importa un export SISTER come nuova edizione in bozza del catalogo di un Comune';

    public function handle(CatalogImporter $importer): int
    {
        $file = (string) $this->argument('file');
        if (! is_file($file) || ! is_readable($file)) {
            $this->error("File non leggibile: {$file}");

            return self::FAILURE;
        }

        $stored = Storage::disk('local')->putFileAs('catalog-imports', new File($file), now()->format('YmdHis').'-'.Str::slug(pathinfo($file, PATHINFO_FILENAME)).'.txt');

        try {
            $run = $importer->prepare((string) $this->argument('code'), $this->option('name') ?: null, (string) $stored, basename($file), $this->option('note') ?: null);
        } catch (RuntimeException $e) {
            Storage::disk('local')->delete((string) $stored);
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $run = $importer->process($run);
        $this->report($run);
        if ($run->status !== 'done') {
            return self::FAILURE;
        }

        if ($this->option('publish')) {
            $importer->publish(CatalogRelease::query()->findOrFail($run->catalog_release_id));
            $this->info('Edizione pubblicata.');
        } else {
            $this->comment('Bozza pronta: carica le sagome con trova:import-parcels, poi pubblica con trova:publish-catalog.');
        }

        return self::SUCCESS;
    }

    private function report(ImportRun $run): void
    {
        $this->info("Righe lette {$run->rows_read}, unità importate {$run->rows_imported}, scartate {$run->rows_rejected}.");
        $summary = $run->summary ?? [];
        $diff = $summary['diff'] ?? null;
        if (is_array($diff)) {
            $this->line("Rispetto all'edizione {$summary['previous_release']}: nuove {$diff['added']}, variate {$diff['changed']}, invariate {$diff['unchanged']}, mancanti {$diff['removed']}.");
        }
        foreach ($run->issues()->limit(15)->get() as $issue) {
            $this->line("  [{$issue->severity}] ".($issue->row_number ? "riga {$issue->row_number}: " : '').$issue->message);
        }
    }
}
