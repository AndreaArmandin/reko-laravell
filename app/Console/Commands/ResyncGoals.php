<?php

namespace App\Console\Commands;

use App\Gestionale\Goals\GoalEngine;
use App\Models\Agency;
use Illuminate\Console\Command;

/**
 * Rebuilds the objectives events and the current ledger from the agenda (repair after a bulk change
 * made outside the application, e.g. roles or groups changed directly in the database).
 */
class ResyncGoals extends Command
{
    protected $signature = 'gestionale:goals-resync {agency? : Agency id (all agencies when omitted)}';

    protected $description = 'Ricalcola eventi e registro degli obiettivi dalle attività dell’agenzia';

    public function handle(GoalEngine $engine): int
    {
        $agencies = Agency::query()->withoutGlobalScopes()->when($this->argument('agency'), fn ($q, $id) => $q->whereKey($id))->pluck('id');
        foreach ($agencies as $id) {
            $count = $engine->resync((int) $id);
            $this->info("Agenzia {$id}: {$count} eventi da attività.");
        }

        return self::SUCCESS;
    }
}
