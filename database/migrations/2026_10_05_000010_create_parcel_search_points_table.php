<?php

use App\Support\Postgis;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Search point of a parcel for one catalog release (Trova reko_catalog_locations).
     * Unlike the optional geometries on versions, a row here always has a point and its source:
     * a parcel without a known point simply has no row.
     */
    public function up(): void
    {
        Postgis::assertPgsql();

        Schema::create('parcel_search_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parcel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('catalog_release_id')->constrained()->restrictOnDelete();
            $table->string('source');
            $table->timestamps();

            $table->unique(['parcel_id', 'catalog_release_id'], 'parcel_search_points_parcel_release_unique');
            $table->index('catalog_release_id');
        });

        Postgis::addGeometry('parcel_search_points', 'location', 'Point');
        DB::statement('ALTER TABLE parcel_search_points ALTER COLUMN location SET NOT NULL');
        Postgis::gist('parcel_search_points', 'location');
        Postgis::check('parcel_search_points', 'parcel_search_points_source_check', "length(trim(source)) > 0");
    }

    public function down(): void
    {
        Schema::dropIfExists('parcel_search_points');
    }
};
