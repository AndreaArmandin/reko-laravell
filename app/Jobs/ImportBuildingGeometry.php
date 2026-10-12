<?php

namespace App\Jobs;

use App\Models\ImportRun;
use App\Trova\BuildingGeometryImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ImportBuildingGeometry implements ShouldQueue
{
    use Queueable;

    public int $timeout = 0;

    public int $tries = 1;

    public function __construct(public int $runId) {}

    public function handle(BuildingGeometryImporter $importer): void
    {
        $run = ImportRun::query()->find($this->runId);
        if ($run !== null && $run->status === 'queued') {
            $importer->process($run);
        }
    }
}
