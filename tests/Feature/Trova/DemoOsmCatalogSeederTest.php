<?php

use App\Models\Building;
use App\Trova\CatalogSearch;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\DemoOsmCatalogSeeder;
use Illuminate\Support\Facades\DB;

it('builds a searchable demo catalogue on real OpenStreetMap buildings', function () {
    $this->seed(DemoOsmCatalogSeeder::class);
    $this->seed(DemoOsmCatalogSeeder::class); // can run again without duplicates

    expect(Building::query()->count())->toBe(474);

    $footprints = DB::selectOne(
        "SELECT count(*) total, count(*) FILTER (WHERE ST_IsValid(footprint) AND GeometryType(footprint) = 'MULTIPOLYGON') valid
         FROM building_versions"
    );
    expect($footprints->valid)->toBe($footprints->total);

    $search = new CatalogSearch;
    expect($search->search(['code' => 'X002', 'segment' => 'private', 'address' => 'Corso Nizza', 'min' => 0.5, 'max' => 1000])->total)->toBe(20)
        ->and($search->search(['code' => 'X002', 'segment' => 'business', 'businessType' => 'shop', 'address' => 'Via Roma', 'sort' => 'address', 'min' => 0.5, 'max' => 1000])->total)->toBe(25);
});

it('returns the real building footprint of each result for the map', function () {
    $this->seed(DemoOsmCatalogSeeder::class);

    $rows = (new CatalogSearch)->search(['code' => 'X002', 'segment' => 'private', 'min' => 0.5, 'max' => 1000])->rows;

    expect($rows)->toHaveCount(10);
    foreach ($rows as $row) {
        expect($row['footprint']['type'])->toBe('MultiPolygon')
            ->and($row['footprint']['coordinates'][0][0][0])->toHaveCount(2); // [longitude, latitude]
    }
});

it('has no footprint where no building is linked', function () {
    $this->seed(DemoCatalogSeeder::class);

    $row = (new CatalogSearch)->search(['code' => 'X001', 'segment' => 'private', 'min' => 0.5, 'max' => 1000])->rows[0];

    expect($row['footprint'])->toBeNull()
        ->and($row['latitude'])->not->toBeNull(); // the map falls back to the pin
});
