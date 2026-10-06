<?php

namespace App\Trova;

use App\Models\ImportIssue;
use App\Models\ImportRun;
use Throwable;

/**
 * A fatal error (memory, timeout) cannot be caught: when PHP dies mid-import this marks the run as failed,
 * so the admin page never shows "in corso" forever.
 */
final class ImportGuard
{
    public bool $done = false;

    public function __construct(ImportRun $run)
    {
        $runId = $run->id;
        register_shutdown_function(function () use ($runId) {
            if ($this->done) {
                return;
            }
            try {
                if (ImportRun::query()->whereKey($runId)->whereIn('status', ['queued', 'running'])->update(['status' => 'failed', 'finished_at' => now()])) {
                    ImportIssue::query()->create(['import_run_id' => $runId, 'severity' => 'error', 'code' => 'import-interrupted', 'message' => 'L’import si è interrotto a metà (memoria o tempo esauriti). Scarta la bozza e riprova.']);
                }
            } catch (Throwable) {
            }
        });
    }
}
