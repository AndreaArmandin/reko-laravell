<?php

namespace App\Jobs;

use App\Models\ImportRun;
use App\Trova\ParcelGeometryImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ImportParcelGeometry implements ShouldQueue
{
    use Queueable;

    public int $timeout = 0;

    public int $tries = 1;

    public function __construct(public int $runId) {}

    public function handle(ParcelGeometryImporter $importer): void
    {
        $run = ImportRun::query()->find($this->runId);
        if ($run !== null && $run->status === 'queued') {
            $importer->process($run);
        }
    }
}
