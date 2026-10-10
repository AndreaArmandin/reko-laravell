<?php

namespace Database\Seeders;

use App\Models\Municipality;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Adds a clearly marked, synthetic area around the X002 demo catalog so the
 * REKO neighborhood picker and its spatial filter can be tried locally.
 */
class DemoNeighborhoodSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command->error('Le aree dimostrative non si creano in produzione.');

            return;
        }

        $municipality = Municipality::query()->where('cadastral_code', 'X002')->first();
        $releaseId = $municipality === null ? null : DB::table('municipality_catalogs')
            ->where('municipality_id', $municipality->id)->value('catalog_release_id');

        if ($municipality === null || $releaseId === null) {
            $this->command->warn('Prima carica il catalogo demo X002.');

            return;
        }

        DB::statement(<<<'SQL'
            INSERT INTO geographic_zones
                (municipality_id, code, name, kind, indicative, notice, source, boundary, created_at, updated_at)
            SELECT ?, 'DEMO-OSM-CENTRO', 'DEMO · Cuneo centro OSM', 'quartiere', true,
                'Area dimostrativa con confine sintetico: serve per provare selezione e filtri e non rappresenta un quartiere o una frazione ufficiale.',
                'REKO demo · geometrie sintetiche basate sul campione OpenStreetMap',
                ST_Multi(ST_Envelope(ST_Collect(boundary))), now(), now()
            FROM parcel_versions
            WHERE catalog_release_id = ? AND boundary IS NOT NULL
            HAVING count(*) > 0
            ON CONFLICT (municipality_id, code) DO UPDATE SET
                name = EXCLUDED.name,
                kind = EXCLUDED.kind,
                indicative = EXCLUDED.indicative,
                notice = EXCLUDED.notice,
                source = EXCLUDED.source,
                boundary = EXCLUDED.boundary,
                updated_at = now()
            SQL, [$municipality->id, $releaseId]);

        $this->command->info('Area di quartiere demo X002 aggiornata (confine sintetico, non ufficiale).');
    }
}
