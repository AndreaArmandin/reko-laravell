<?php

namespace App\Console\Commands;

use App\Trova\IstatMunicipalities;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ImportIstatMunicipalities extends Command
{
    protected $signature = 'reko:import-istat
        {--file= : XLSX (o CSV con «;») già scaricato; senza, scarica l’elenco ufficiale ISTAT}';

    protected $description = 'Importa regioni, province e l’elenco nazionale dei Comuni ISTAT (dati di riferimento per i suggerimenti)';

    public function handle(): int
    {
        $path = $this->option('file');
        $temp = null;
        try {
            if (! $path) {
                $this->line('Scarico '.IstatMunicipalities::URL.' …');
                $response = Http::timeout(60)->withHeaders(['User-Agent' => 'REKO'])->get(IstatMunicipalities::URL);
                if (! $response->successful()) {
                    throw new RuntimeException("Download non riuscito (HTTP {$response->status()}): usa --file con l’XLSX scaricato a mano.");
                }
                $temp = tempnam(sys_get_temp_dir(), 'istat').'.xlsx';
                file_put_contents($temp, $response->body());
                $path = $temp;
            } elseif (! is_file($path)) {
                throw new RuntimeException("File non trovato: {$path}");
            }

            $counts = IstatMunicipalities::load(IstatMunicipalities::read($path));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            if ($temp) {
                @unlink($temp);
            }
        }

        $this->info("Regioni {$counts['regions']}, province {$counts['provinces']}, Comuni {$counts['municipalities']}; province aggiunte ai Comuni della piattaforma: {$counts['backfilled']}.");

        return self::SUCCESS;
    }
}
