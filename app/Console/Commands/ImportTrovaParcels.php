<?php

namespace App\Console\Commands;

use App\Models\CatalogRelease;
use App\Trova\CatalogImporter;
use App\Trova\ParcelGeometryImporter;
use Illuminate\Console\Command;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class ImportTrovaParcels extends Command
{
    protected $signature = 'trova:import-parcels
        {code : codice catastale del Comune che ha una bozza}
        {file : GeoJSON con le sagome delle particelle}
        {--sheet= : proprietà con il foglio}
        {--parcel= : proprietà con il numero di particella}
        {--section= : proprietà con la sezione (facoltativa)}';

    protected $description = 'Carica le sagome delle particelle sulla bozza del catalogo di un Comune';

    public function handle(ParcelGeometryImporter $importer): int
    {
        $file = (string) $this->argument('file');
        if (! is_file($file) || ! is_readable($file)) {
            $this->error("File non leggibile: {$file}");

            return self::FAILURE;
        }

        $release = CatalogRelease::query()->where('status', 'draft')
            ->whereHas('municipality', fn ($q) => $q->where('cadastral_code', strtoupper((string) $this->argument('code'))))->first();
        if ($release === null) {
            $this->error('Questo Comune non ha una bozza: importa prima un file SISTER con trova:import-catalog.');

            return self::FAILURE;
        }

        $stored = Storage::disk('local')->putFileAs('catalog-imports', new File($file), now()->format('YmdHis').'-'.Str::slug(pathinfo($file, PATHINFO_FILENAME)).'.geojson');
        try {
            $run = $importer->prepare($release, (string) $stored, basename($file), [
                'sheet' => (string) $this->option('sheet'), 'parcel' => (string) $this->option('parcel'), 'section' => (string) $this->option('section'),
            ]);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $run = $importer->process($run);
        $coverage = CatalogImporter::coverage($release->id);
        $this->info("Sagome lette {$run->rows_read}, particelle collocate {$run->rows_imported}, scartate {$run->rows_rejected}.");
        $this->line("Particelle della bozza con una posizione: {$coverage['located']} su {$coverage['parcels']}.");
        foreach ($run->issues()->limit(15)->get() as $issue) {
            $this->line("  [{$issue->severity}] {$issue->message}");
        }

        return $run->status === 'done' ? self::SUCCESS : self::FAILURE;
    }
}
