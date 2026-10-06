<?php

namespace App\Jobs;

use App\Models\ImportRun;
use App\Trova\CatalogImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ImportSisterCatalog implements ShouldQueue
{
    use Queueable;

    public int $timeout = 0;

    public int $tries = 1;

    public function __construct(public int $runId) {}

    public function handle(CatalogImporter $importer): void
    {
        $run = ImportRun::query()->find($this->runId);
        if ($run !== null && $run->status === 'queued') {
            $importer->process($run);
        }
    }
}
