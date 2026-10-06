<?php

namespace App\Console\Commands;

use App\Models\CatalogRelease;
use App\Trova\CatalogImporter;
use Illuminate\Console\Command;
use RuntimeException;

class PublishTrovaCatalog extends Command
{
    protected $signature = 'trova:publish-catalog {code : codice catastale del Comune} {--discard : scarta la bozza invece di pubblicarla}';

    protected $description = 'Pubblica (o scarta) la bozza del catalogo di un Comune';

    public function handle(CatalogImporter $importer): int
    {
        $release = CatalogRelease::query()->where('status', 'draft')
            ->whereHas('municipality', fn ($q) => $q->where('cadastral_code', strtoupper((string) $this->argument('code'))))->first();
        if ($release === null) {
            $this->error('Questo Comune non ha una bozza.');

            return self::FAILURE;
        }

        try {
            if ($this->option('discard')) {
                $importer->discard($release);
                $this->info('Bozza scartata.');
            } else {
                $importer->publish($release);
                $this->info("Edizione {$release->code} pubblicata.");
            }
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
