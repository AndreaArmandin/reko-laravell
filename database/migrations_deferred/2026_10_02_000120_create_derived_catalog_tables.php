<?php

use App\Support\Postgis;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Derived territorial and address tables (draft).
     * geographic_zones, omi_zones and omi_quotations are active with another schema in
     * database/migrations/2026_10_06_000020_create_trova_reference_tables.php; housing data lives
     * in parcel_housing_contexts (2026_10_05_000020). They were removed from here so this draft
     * can never collide with them.
     * Schema stubs: columns the later imports will fill, with nullable measures and geometries.
     */
    public function up(): void
    {
        Postgis::assertPgsql();

        Schema::create('functional_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parcel_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('catalog_release_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code')->nullable();
            $table->decimal('area_sqm', 16, 2)->nullable();
            $table->timestamps();
        });

        Postgis::addGeometry('functional_lots', 'boundary', 'MultiPolygon');
        Postgis::gist('functional_lots', 'boundary');

        Schema::create('address_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->string('street')->nullable();
            $table->string('number')->nullable();
            $table->timestamps();
        });

        Postgis::addGeometry('address_points', 'location', 'Point');
        Postgis::gist('address_points', 'location');

        Schema::create('business_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name')->nullable();
            $table->string('category')->nullable();
            $table->timestamps();
        });

        Postgis::addGeometry('business_locations', 'location', 'Point');
        Postgis::gist('business_locations', 'location');

        Schema::create('territorial_localities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
        });

        Postgis::addGeometry('territorial_localities', 'location', 'Point');
        Postgis::gist('territorial_localities', 'location');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('territorial_localities');
        Schema::dropIfExists('business_locations');
        Schema::dropIfExists('address_points');
        Schema::dropIfExists('functional_lots');
    }
};
