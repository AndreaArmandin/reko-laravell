<?php

namespace Database\Seeders;

use App\Models\CadastralUnit;
use App\Models\CadastralUnitVersion;
use App\Models\CatalogRelease;
use App\Models\Municipality;
use App\Models\MunicipalityCatalog;
use App\Models\Parcel;
use App\Trova\Categories;
use App\Trova\UnitFacts;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Random but repeatable demo catalogue for trying Trova search locally.
 * Uses the fictitious code X001 so it can never collide with a real Comune.
 * Not called by DatabaseSeeder: run it with
 * php artisan db:seed --class=DemoCatalogSeeder
 */
class DemoCatalogSeeder extends Seeder
{
    private const CODE = 'X001';

    private const CENTER = ['lat' => 44.5, 'lng' => 8.0];

    private const STREETS = [
        'VIA ROMA', 'VIA ROMAGNA', 'CORSO ITALIA', 'VIA GARIBALDI', 'VIA MAZZINI', 'VIA CAVOUR',
        'PIAZZA DELLA LIBERTA', 'VIA DEI MILLE', 'VIA VERDI', 'VIA MATTEOTTI', 'VIALE DELLA REPUBBLICA',
        'VIA SANT\'ANNA', 'VIA XX SETTEMBRE', 'VIA 24 MAGGIO', 'LOCALITA SAN ROCCO', 'STRADA PROVINCIALE 12',
        'VIA DEL LAVORO', 'VIA DELL\'INDUSTRIA', 'VIA BOSCO', 'VICOLO STRETTO',
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command->error('Il catalogo demo non si crea in produzione.');

            return;
        }

        mt_srand(2026);

        DB::transaction(function () {
            $municipality = Municipality::query()->firstOrCreate(
                ['cadastral_code' => self::CODE],
                ['name' => 'Comune Demo'],
            );
            $this->forget($municipality);

            $release = CatalogRelease::query()->create([
                'municipality_id' => $municipality->id,
                'code' => 'DEMO-'.self::CODE,
                'label' => 'Dati casuali di prova',
                'released_on' => now()->toDateString(),
                'status' => 'active',
            ]);

            $streetPoints = array_map(fn () => [
                'lat' => self::CENTER['lat'] + $this->between(-0.02, 0.02),
                'lng' => self::CENTER['lng'] + $this->between(-0.03, 0.03),
            ], self::STREETS);

            $taken = [];
            for ($i = 0; $i < 400; $i++) {
                do {
                    $sheet = (string) mt_rand(1, 30);
                    $number = (string) mt_rand(1, 999);
                } while (isset($taken["$sheet/$number"]));
                $taken["$sheet/$number"] = true;

                $parcel = Parcel::query()->create([
                    'municipality_id' => $municipality->id,
                    'cadastral_kind' => 'F',
                    'section' => '',
                    'sheet' => $sheet,
                    'number' => $number,
                ]);

                $streetIndex = array_rand(self::STREETS);
                $street = self::STREETS[$streetIndex];
                $civic = mt_rand(1, 180);

                foreach ($this->units() as $sub => [$category, $floor]) {
                    $this->unit($parcel, $release, $sub + 1, $category, "{$street} n. {$civic} Piano {$floor}");
                }

                // Some parcels have no known position, as in the real archive.
                if (mt_rand(1, 100) <= 92) {
                    $point = $streetPoints[$streetIndex];
                    DB::insert(
                        'insert into parcel_search_points (parcel_id, catalog_release_id, source, location, created_at, updated_at)
                         values (?, ?, ?, ST_SetSRID(ST_MakePoint(?, ?), 4326), now(), now())',
                        [$parcel->id, $release->id, 'demo-casuale',
                            $point['lng'] + $this->between(-0.002, 0.002), $point['lat'] + $this->between(-0.002, 0.002)],
                    );
                }
            }

            MunicipalityCatalog::query()->create([
                'municipality_id' => $municipality->id,
                'catalog_release_id' => $release->id,
                'activated_at' => now(),
            ]);

            // Piani e classificazione abitativa, come l'indice precalcolato di Trova
            app(UnitFacts::class)->build($release->id);
        });

        $units = CadastralUnitVersion::query()->whereHas('catalogRelease', fn ($q) => $q->where('code', 'DEMO-'.self::CODE))->count();
        $this->command->info("Comune Demo (X001): 400 particelle, {$units} unità.");
    }

    /**
     * Removes a previous demo catalogue, so the seeder can run again.
     */
    private function forget(Municipality $municipality): void
    {
        $releases = CatalogRelease::query()->where('municipality_id', $municipality->id)->pluck('id');
        $parcels = Parcel::query()->where('municipality_id', $municipality->id)->pluck('id');

        MunicipalityCatalog::query()->where('municipality_id', $municipality->id)->delete();
        CadastralUnitVersion::query()->whereIn('catalog_release_id', $releases)->delete();
        DB::table('parcel_search_points')->whereIn('catalog_release_id', $releases)->delete();
        DB::table('parcel_housing_contexts')->whereIn('catalog_release_id', $releases)->delete();
        CadastralUnit::query()->whereIn('parcel_id', $parcels)->delete();
        Parcel::query()->whereIn('id', $parcels)->delete();
        CatalogRelease::query()->whereIn('id', $releases)->delete();
    }

    /**
     * Units of one parcel as [category, floor], from a random building profile.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function units(): array
    {
        $units = [];
        $add = function (int $count, array $categories, array $floors) use (&$units) {
            for ($i = 0; $i < $count; $i++) {
                $units[] = [$categories[array_rand($categories)], (string) $floors[array_rand($floors)]];
            }
        };

        $profile = mt_rand(1, 100);
        if ($profile <= 35) { // condominio
            $add(mt_rand(4, 20), ['A/2', 'A/3', 'A/3'], ['1', '2', '3', '4', '5']);
            $add(mt_rand(0, 8), ['C/6'], ['S1', 'T']);
            $add(mt_rand(0, 6), ['C/2'], ['S1']);
            if (mt_rand(1, 100) <= 30) {
                $add(mt_rand(1, 2), ['C/1'], ['T']);
            }
        } elseif ($profile <= 60) { // villetta
            $add(mt_rand(1, 2), ['A/7', 'A/7', 'A/2', 'A/8'], ['T', '1']);
            $add(mt_rand(0, 2), ['C/6'], ['T']);
            $add(mt_rand(0, 1), ['C/2'], ['S1']);
        } elseif ($profile <= 70) { // commerciale
            $add(mt_rand(1, 4), ['C/1'], ['T']);
            $add(mt_rand(0, 3), ['A/10'], ['1', '2']);
            $add(mt_rand(0, 2), ['C/2'], ['S1']);
        } elseif ($profile <= 78) { // uffici
            $add(mt_rand(2, 8), ['A/10'], ['1', '2', '3']);
        } elseif ($profile <= 88) { // produttivo
            $add(mt_rand(1, 3), ['D/1', 'D/7', 'D/8'], ['T']);
            $add(mt_rand(0, 2), ['C/3'], ['T']);
            $add(mt_rand(0, 2), ['C/2'], ['T']);
        } elseif ($profile <= 92) { // pubblico: sempre nascosto nella ricerca
            $add(1, ['B/1', 'B/4', 'E/1'], ['T']);
        } else { // rurale o vecchio
            $add(mt_rand(1, 3), ['A/4', 'A/5', 'A/6'], ['T', '1']);
            $add(mt_rand(0, 2), ['C/2', 'C/6'], ['T']);
            $add(mt_rand(0, 1), ['F/2', 'F/3'], ['T']);
        }

        return $units;
    }

    private function unit(Parcel $parcel, CatalogRelease $release, int $sub, string $category, string $address): void
    {
        // A few units have no subalterno, as in SISTER records.
        $withoutSub = mt_rand(1, 100) <= 2;
        $unit = CadastralUnit::query()->create([
            'parcel_id' => $parcel->id,
            'subalterno' => $withoutSub ? null : (string) $sub,
            'source_ref' => $withoutSub ? "demo:{$parcel->id}:{$sub}" : null,
        ]);

        $unitOfMeasure = Categories::dimensionUnit($category);
        $value = match ($unitOfMeasure) {
            'vani' => str_starts_with($category, 'A/10') ? mt_rand(6, 30) / 2 : mt_rand(4, 20) / 2,
            'm²' => match ($category) {
                'C/1' => mt_rand(30, 400),
                'C/3' => mt_rand(80, 800),
                'C/6' => mt_rand(12, 40),
                default => mt_rand(4, 60),
            },
            'm³' => mt_rand(500, 5000),
            default => null,
        };
        // Some units have no consistency in the source.
        if (mt_rand(1, 100) <= 3) {
            $value = null;
        }
        $excluded = mt_rand(1, 100) <= 3;

        CadastralUnitVersion::query()->create([
            'cadastral_unit_id' => $unit->id,
            'catalog_release_id' => $release->id,
            'status' => $excluded ? 'excluded' : 'eligible',
            'status_reason' => $excluded ? 'Bene comune non censibile' : null,
            'category' => $category,
            'search_group' => Categories::group($category),
            'consistency' => $value,
            'consistency_unit' => $value === null ? null : $unitOfMeasure,
            'address_raw' => $address,
        ]);
    }

    private function between(float $min, float $max): float
    {
        return $min + mt_rand() / mt_getrandmax() * ($max - $min);
    }
}
