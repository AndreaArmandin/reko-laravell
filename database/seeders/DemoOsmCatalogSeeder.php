<?php

namespace Database\Seeders;

use App\Models\Building;
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
use RuntimeException;

/**
 * Demo catalogue on real OpenStreetMap buildings and addresses of central Cuneo
 * (database/data/osm-cuneo-centro.json, © OpenStreetMap contributors, ODbL).
 * Footprints and civic numbers are real; parcels, subalterni, categories and
 * consistencies are invented from the building type and its shops and offices.
 * Uses the fictitious code X002, so it never collides with the real Cuneo (D205).
 *
 * php artisan db:seed --class=DemoOsmCatalogSeeder
 */
class DemoOsmCatalogSeeder extends Seeder
{
    private const CODE = 'X002';

    private const SOURCE = 'data/osm-cuneo-centro.json';

    /** South-west corner of the downloaded area: invented sheets are a grid from here. */
    private const ORIGIN = ['lat' => 44.3848, 'lng' => 7.5414];

    private const SHEET_SIZE = 0.0025; // degrees, about 200–280 m

    private const SHEET_COLUMNS = 6;

    private const MAX_UNITS = 150;

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command->error('Il catalogo demo non si crea in produzione.');

            return;
        }

        mt_srand(2027);

        $osm = json_decode((string) file_get_contents(database_path(self::SOURCE)), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($osm) || ! is_array($osm['elements'] ?? null)) {
            throw new RuntimeException(self::SOURCE.' non è un export Overpass valido.');
        }
        $elements = collect($osm['elements']);
        $ways = $elements->where('type', 'way')->sortBy('id')->values();
        $addresses = $elements->where('type', 'node')->values();

        DB::transaction(function () use ($ways, $addresses) {
            $municipality = Municipality::query()->firstOrCreate(
                ['cadastral_code' => self::CODE],
                ['name' => 'Cuneo (demo OSM)'],
            );
            $this->forget($municipality);

            $release = CatalogRelease::query()->create([
                'municipality_id' => $municipality->id,
                'code' => 'DEMO-'.self::CODE,
                'label' => 'Edifici OSM · geometrie e catasto fittizi',
                'released_on' => now()->toDateString(),
                'status' => 'active',
                'notes' => 'Dati dimostrativi: per poter provare la mappa, il contorno dell’edificio OSM è usato come geometria fittizia della particella. Non rappresenta un confine catastale reale.',
            ]);

            $buildings = $this->buildings($municipality, $release, $ways);
            $addressesByBuilding = $this->addresses($release, $addresses);

            $sheets = [];
            foreach ($buildings as $buildingId => $building) {
                $point = DB::selectOne(
                    'SELECT ST_X(p) lng, ST_Y(p) lat FROM (SELECT ST_PointOnSurface(footprint) p FROM building_versions WHERE building_id = ? AND catalog_release_id = ?) s',
                    [$buildingId, $release->id],
                );
                $column = min(self::SHEET_COLUMNS - 1, max(0, (int) floor(($point->lng - self::ORIGIN['lng']) / self::SHEET_SIZE)));
                $row = max(0, (int) floor(($point->lat - self::ORIGIN['lat']) / self::SHEET_SIZE));
                $sheet = (string) (1 + $row * self::SHEET_COLUMNS + $column);
                $sheets[$sheet] = ($sheets[$sheet] ?? 0) + 1;

                $parcel = Parcel::query()->create([
                    'municipality_id' => $municipality->id,
                    'cadastral_kind' => 'F',
                    'section' => '',
                    'sheet' => $sheet,
                    'number' => (string) $sheets[$sheet],
                ]);

                DB::insert(
                    'INSERT INTO building_parcel_links (catalog_release_id, building_id, parcel_id, created_at, updated_at) VALUES (?, ?, ?, now(), now())',
                    [$release->id, $buildingId, $parcel->id],
                );
                DB::insert(
                    'INSERT INTO parcel_versions (parcel_id, catalog_release_id, area_sqm, boundary, created_at, updated_at)
                     SELECT ?, ?, round(ST_Area(footprint::geography)::numeric, 2), footprint, now(), now()
                     FROM building_versions WHERE building_id = ? AND catalog_release_id = ? AND footprint IS NOT NULL',
                    [$parcel->id, $release->id, $buildingId, $release->id],
                );
                DB::insert(
                    "INSERT INTO parcel_search_points (parcel_id, catalog_release_id, source, location, created_at, updated_at)
                     SELECT ?, ?, 'osm-demo', ST_PointOnSurface(footprint), now(), now() FROM building_versions WHERE building_id = ? AND catalog_release_id = ?",
                    [$parcel->id, $release->id, $buildingId, $release->id],
                );

                $this->units($parcel, $release, $building['tags'], $building['area'], $addressesByBuilding[$buildingId] ?? []);
            }

            MunicipalityCatalog::query()->create([
                'municipality_id' => $municipality->id,
                'catalog_release_id' => $release->id,
                'activated_at' => now(),
            ]);

            // Piani e classificazione abitativa, come l'indice precalcolato di Trova
            app(UnitFacts::class)->build($release->id);
        });

        $this->call(DemoNeighborhoodSeeder::class);

        $units = CadastralUnitVersion::query()->whereHas('catalogRelease', fn ($q) => $q->where('code', 'DEMO-'.self::CODE))->count();
        $parcels = Parcel::query()->whereHas('municipality', fn ($q) => $q->where('cadastral_code', self::CODE))->count();
        $this->command->info("Cuneo (demo OSM, X002): {$parcels} edifici e particelle, {$units} unità.");
    }

    /**
     * Real footprints. Invalid OSM rings are repaired; tiny sheds are skipped.
     *
     * @param  iterable<array<string, mixed>>  $ways
     * @return array<int, array{tags: array<string, string>, area: float}>
     */
    private function buildings(Municipality $municipality, CatalogRelease $release, iterable $ways): array
    {
        $buildings = [];

        foreach ($ways as $way) {
            $ring = array_map(fn (array $point) => [$point['lon'], $point['lat']], $way['geometry']);
            $building = Building::query()->create([
                'municipality_id' => $municipality->id,
                'cadastral_building_id' => 'osm:way/'.$way['id'],
            ]);

            $version = DB::selectOne(
                'INSERT INTO building_versions (building_id, catalog_release_id, area_sqm, footprint, created_at, updated_at)
                 SELECT ?, ?, round(ST_Area(g::geography)::numeric, 2), g, now(), now()
                 FROM (SELECT ST_Multi(ST_CollectionExtract(ST_MakeValid(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326)), 3)) g) s
                 WHERE NOT ST_IsEmpty(g)
                 RETURNING area_sqm',
                [$building->id, $release->id, json_encode(['type' => 'Polygon', 'coordinates' => [$ring]])],
            );

            if ($version === null || (float) $version->area_sqm < 20) {
                $building->delete();

                continue;
            }

            $buildings[$building->id] = ['tags' => $way['tags'] ?? [], 'area' => (float) $version->area_sqm];
        }

        return $buildings;
    }

    /**
     * Each civic number goes to the nearest building within 20 m (PostGIS, distances in metres).
     *
     * @param  iterable<array<string, mixed>>  $nodes
     * @return array<int, list<array{address: string, tags: array<string, string>}>>
     */
    private function addresses(CatalogRelease $release, iterable $nodes): array
    {
        // Inside an outer transaction (tests) ON COMMIT DROP has not fired yet: drop it explicitly.
        DB::statement('DROP TABLE IF EXISTS pg_temp.osm_addresses');
        DB::statement('CREATE TEMPORARY TABLE osm_addresses (id bigint PRIMARY KEY, address text, tags jsonb, location geometry(Point, 4326)) ON COMMIT DROP');

        foreach (collect($nodes)->chunk(500) as $chunk) {
            $values = [];
            $bindings = [];
            foreach ($chunk as $node) {
                $tags = $node['tags'];
                if (! isset($tags['addr:street'], $tags['addr:housenumber'])) {
                    continue;
                }
                $values[] = '(?, ?, ?, ST_SetSRID(ST_MakePoint(?, ?), 4326))';
                array_push($bindings, $node['id'], mb_strtoupper($tags['addr:street']).' n. '.mb_strtoupper($tags['addr:housenumber']),
                    json_encode($tags), $node['lon'], $node['lat']);
            }
            if ($values !== []) {
                DB::insert('INSERT INTO osm_addresses (id, address, tags, location) VALUES '.implode(', ', $values), $bindings);
            }
        }

        $matches = DB::select(
            'SELECT DISTINCT ON (a.id) a.id, bv.building_id, a.address, a.tags
             FROM osm_addresses a
             JOIN building_versions bv ON bv.catalog_release_id = ?
                AND bv.footprint && ST_Expand(a.location, 0.0004)
                AND ST_DWithin(bv.footprint::geography, a.location::geography, 20)
             ORDER BY a.id, ST_Distance(bv.footprint::geography, a.location::geography)',
            [$release->id],
        );

        $byBuilding = [];
        foreach ($matches as $match) {
            $byBuilding[$match->building_id][] = ['address' => $match->address, 'tags' => json_decode($match->tags, true)];
        }
        foreach ($byBuilding as &$list) {
            usort($list, fn ($a, $b) => strnatcmp($a['address'], $b['address']));
        }

        return $byBuilding;
    }

    /**
     * Invented units that fit the OSM building type, its size and the shops and offices at its civic numbers.
     *
     * @param  array<string, string>  $tags
     * @param  list<array{address: string, tags: array<string, string>}>  $addresses
     */
    private function units(Parcel $parcel, CatalogRelease $release, array $tags, float $area, array $addresses): void
    {
        $type = $tags['building'] ?? 'yes';
        $levels = max(0, (int) ($tags['building:levels'] ?? 0));
        $shops = [];
        $offices = [];
        foreach ($addresses as $candidate) {
            if (isset($candidate['tags']['shop']) || isset($candidate['tags']['amenity']) || isset($candidate['tags']['craft'])) {
                $shops[] = $candidate;
            }
            if (isset($candidate['tags']['office'])) {
                $offices[] = $candidate;
            }
        }
        $units = [];
        $address = fn (int $i): ?string => $addresses === [] ? null : $addresses[$i % count($addresses)]['address'];
        $add = function (string $category, string $floor, ?float $value, ?string $at = null) use (&$units, $address) {
            $units[] = [$category, $floor, $value, $at ?? $address(count($units))];
        };

        $kind = match (true) {
            in_array($type, ['church', 'cathedral', 'chapel'], true) => 'worship',
            in_array($type, ['school', 'kindergarten', 'university'], true) => 'school',
            in_array($type, ['government', 'public', 'civic', 'hospital'], true) => 'public',
            in_array($type, ['roof', 'parking', 'sport', 'office', 'hotel'], true) => $type,
            in_array($type, ['retail', 'commercial', 'supermarket'], true) => 'retail',
            in_array($type, ['industrial', 'warehouse'], true) => 'industrial',
            in_array($type, ['house', 'detached', 'semidetached_house', 'terrace'], true), $type === 'yes' && $area < 140 => 'house',
            default => 'residential',
        };

        switch ($kind) {
            case 'worship':
                $add('E/7', 'T', null);
                break;
            case 'school':
                $add('B/5', 'T', round($area * max(2, $levels) * 3.2));
                break;
            case 'public':
                $add('B/4', 'T', round($area * max(2, $levels) * 3.2));
                break;
            case 'roof':
                $add('C/7', 'T', round($area));
                break;
            case 'sport':
                $add('D/6', 'T', null);
                break;
            case 'hotel':
                $add('D/2', 'T', null);
                break;
            case 'industrial':
                $add(['D/1', 'D/7'][mt_rand(0, 1)], 'T', null);
                for ($i = mt_rand(0, 2); $i > 0; $i--) {
                    $add('C/2', 'T', mt_rand(20, 200));
                }
                break;
            case 'parking':
                for ($i = (int) min(80, max(4, $area / 25)); $i > 0; $i--) {
                    $add('C/6', ['S1', 'T'][mt_rand(0, 1)], mt_rand(12, 18));
                }
                break;
            case 'retail':
                $count = (int) min(6, max(1, $area / 150));
                for ($i = 0; $i < $count; $i++) {
                    $add('C/1', 'T', round($area / $count), $shops[$i]['address'] ?? null);
                }
                for ($i = mt_rand(0, 2); $i > 0; $i--) {
                    $add('C/2', 'S1', mt_rand(10, 80));
                }
                break;
            case 'office':
                $perFloor = (int) min(4, max(1, $area / 150));
                for ($floor = 1; $floor <= max(2, $levels); $floor++) {
                    for ($i = 0; $i < $perFloor; $i++) {
                        $add('A/10', (string) $floor, max(2, round($area * 0.8 / $perFloor / 16 * 2) / 2));
                    }
                }
                break;
            case 'house':
                $add(mt_rand(1, 100) <= 70 ? 'A/7' : 'A/2', 'T', min(12, max(4, round($area / 20 * 2) / 2)));
                if (mt_rand(1, 100) <= 60) {
                    $add('C/6', 'T', mt_rand(14, 35));
                }
                break;
            default: // residential block, often a whole block of the historic centre
                $floors = $levels > 0 ? $levels : mt_rand(3, 6);
                $perFloor = (int) min(8, max(1, round($area * 0.8 / 90)));
                $roomsEach = min(10, max(2, round($area * 0.8 / $perFloor / 16 * 2) / 2));
                foreach (array_slice($shops, 0, $perFloor * 2) as $shop) {
                    $add('C/1', 'T', mt_rand(30, 250), $shop['address']);
                }
                if ($shops === [] && mt_rand(1, 100) <= 35) {
                    $add('C/1', 'T', mt_rand(30, 250));
                }
                foreach ($offices as $office) {
                    $add('A/10', '1', mt_rand(6, 20) / 2, $office['address']);
                }
                for ($floor = 1; $floor < $floors; $floor++) {
                    for ($i = 0; $i < $perFloor; $i++) {
                        $roll = mt_rand(1, 100);
                        $category = $roll <= 5 ? 'A/1' : ($roll <= 65 ? 'A/2' : 'A/3');
                        $add($category, (string) $floor, max(1.5, $roomsEach + mt_rand(-2, 2) / 2));
                    }
                }
                if (mt_rand(1, 100) <= 50) {
                    for ($i = mt_rand(1, max(1, intdiv($perFloor * $floors, 2))); $i > 0; $i--) {
                        $add('C/6', 'S1', mt_rand(12, 25));
                    }
                }
                for ($i = mt_rand(0, $perFloor); $i > 0; $i--) {
                    $add('C/2', 'S1', mt_rand(4, 15));
                }
        }

        $this->insertUnits($parcel, $release, array_slice($units, 0, self::MAX_UNITS));
    }

    /**
     * @param  list<array{0: string, 1: string, 2: float|int|null, 3: string|null}>  $units
     */
    private function insertUnits(Parcel $parcel, CatalogRelease $release, array $units): void
    {
        $identities = [];
        foreach ($units as $i => $unit) {
            // A few units have no subalterno, as in SISTER records.
            $withoutSub = mt_rand(1, 100) <= 2;
            $identities[] = [
                'parcel_id' => $parcel->id,
                'subalterno' => $withoutSub ? null : (string) ($i + 1),
                'source_ref' => $withoutSub ? "osm-demo:{$parcel->id}:".($i + 1) : null,
                'legacy_key' => json_encode([self::CODE, 'Fabbricati', (string) $parcel->section, (string) $parcel->sheet,
                    (string) $parcel->number, $withoutSub ? '' : (string) ($i + 1), ...($withoutSub ? ["osm-demo:{$parcel->id}:".($i + 1)] : [])], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        CadastralUnit::query()->insert($identities);
        $ids = CadastralUnit::query()->where('parcel_id', $parcel->id)->get()
            ->mapWithKeys(fn (CadastralUnit $unit) => [$unit->subalterno ?? $unit->source_ref => $unit->id]);

        $versions = [];
        foreach ($units as $i => [$category, $floor, $value, $address]) {
            $identity = $identities[$i]['subalterno'] ?? $identities[$i]['source_ref'];
            // Some records have no consistency or are excluded, as in the real archive.
            $value = mt_rand(1, 100) <= 3 ? null : $value;
            $excluded = mt_rand(1, 100) <= 3;
            $versions[] = [
                'cadastral_unit_id' => $ids[$identity],
                'catalog_release_id' => $release->id,
                'status' => $excluded ? 'excluded' : 'eligible',
                'status_reason' => $excluded ? 'Bene comune non censibile' : null,
                'category' => $category,
                'search_group' => Categories::group($category),
                'consistency' => $value,
                'consistency_unit' => $value === null ? null : Categories::dimensionUnit($category),
                'address_raw' => $address === null ? null : "{$address} Piano {$floor}",
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        CadastralUnitVersion::query()->insert($versions);
    }

    /**
     * Removes a previous demo catalogue of X002, so the seeder can run again.
     */
    private function forget(Municipality $municipality): void
    {
        $releases = CatalogRelease::query()->where('municipality_id', $municipality->id)->pluck('id');
        $parcels = Parcel::query()->where('municipality_id', $municipality->id)->pluck('id');

        MunicipalityCatalog::query()->where('municipality_id', $municipality->id)->delete();
        CadastralUnitVersion::query()->whereIn('catalog_release_id', $releases)->delete();
        foreach (['parcel_search_points', 'parcel_housing_contexts', 'building_parcel_links', 'building_versions', 'parcel_versions'] as $table) {
            DB::table($table)->whereIn('catalog_release_id', $releases)->delete();
        }
        CadastralUnit::query()->whereIn('parcel_id', $parcels)->delete();
        Parcel::query()->whereIn('id', $parcels)->delete();
        Building::query()->where('municipality_id', $municipality->id)->delete();
        CatalogRelease::query()->whereIn('id', $releases)->delete();
    }
}
