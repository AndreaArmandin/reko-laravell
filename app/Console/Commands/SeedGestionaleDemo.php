<?php

namespace App\Console\Commands;

use App\Gestionale\CurrentAgency;
use App\Models\Agency;
use App\Models\User;
use Database\Seeders\GestionaleDemoSeeder;
use Illuminate\Console\Command;

class SeedGestionaleDemo extends Command
{
    protected $signature = 'gestionale:seed-demo {--agency= : ID agenzia esistente} {--user= : ID utente da collegare come responsabile di test}';

    protected $description = 'Crea dati sintetici idempotenti per provare il Gestionale su un database di test.';

    public function handle(GestionaleDemoSeeder $seeder, CurrentAgency $current): int
    {
        if (app()->isProduction()) {
            $this->error('I dati demo non possono essere creati in produzione.');
            return self::FAILURE;
        }
        $agency = $this->option('agency') ? Agency::query()->find($this->option('agency')) : Agency::query()->orderBy('id')->first();
        $user = $this->option('user') ? User::query()->find($this->option('user')) : User::query()->where('is_admin', true)->orderBy('id')->first();
        if (! $agency || ! $user) {
            $this->error('Indica un’agenzia e un utente esistenti con --agency e --user.');
            return self::FAILURE;
        }

        $counts = $current->runAs($agency, fn () => $seeder->seed($agency, $user));
        $this->info("Dati demo pronti per {$agency->name}: {$counts['clients']} clienti, {$counts['requests']} richieste, {$counts['properties']} immobili, {$counts['activities']} attività.");
        if ($counts['units'] > 0) $this->line("{$counts['units']} unità catastali Trova collegate a immobili demo.");
        $this->line('Il tuo account è collegato all’agenzia come responsabile di test. Tutti i dati demo hanno il prefisso DEMO.');

        return self::SUCCESS;
    }
}
