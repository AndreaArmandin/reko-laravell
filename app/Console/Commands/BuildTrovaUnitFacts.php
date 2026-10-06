<?php

namespace App\Console\Commands;

use App\Trova\UnitFacts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BuildTrovaUnitFacts extends Command
{
    protected $signature = 'trova:unit-facts {code? : Codice catastale del Comune (tutti se omesso)}';

    protected $description = 'Ricalcola piani e classificazione abitativa (appartamento / casa indipendente) delle edizioni attive';

    public function handle(UnitFacts $facts): int
    {
        $releases = DB::table('municipality_catalogs as c')
            ->join('municipalities as m', 'm.id', '=', 'c.municipality_id')
            ->when($this->argument('code'), fn ($q, $code) => $q->where('m.cadastral_code', strtoupper((string) $code)))
            ->get(['m.cadastral_code', 'c.catalog_release_id']);

        if ($releases->isEmpty()) {
            $this->error('Nessun Comune con archivio attivo.');

            return self::FAILURE;
        }

        foreach ($releases as $release) {
            $count = $facts->build((int) $release->catalog_release_id);
            $this->info("{$release->cadastral_code}: {$count} unità classificate.");
        }

        return self::SUCCESS;
    }
}
