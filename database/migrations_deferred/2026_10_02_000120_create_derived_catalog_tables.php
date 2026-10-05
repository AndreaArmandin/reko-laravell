<?php

use App\Support\Postgis;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Derived territorial, address, housing, and OMI tables.
     * Schema stubs: columns the later imports will fill, with nullable measures and geometries.
     */
    public function up(): void
    {
        Postgis::assertPgsql();

        Schema::create('housing_contexts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('catalog_release_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Postgis::addGeometry('housing_contexts', 'boundary', 'MultiPolygon');
        Postgis::gist('housing_contexts', 'boundary');

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

        Schema::create('geographic_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind');
            $table->string('name');
            $table->string('code')->nullable();
            $table->timestamps();
        });

        Postgis::addGeometry('geographic_zones', 'boundary', 'MultiPolygon');
        Postgis::gist('geographic_zones', 'boundary');

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

        Schema::create('omi_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name')->nullable();
            $table->timestamps();

            $table->unique(['municipality_id', 'code']);
        });

        Postgis::addGeometry('omi_zones', 'boundary', 'MultiPolygon');
        Postgis::gist('omi_zones', 'boundary');

        Schema::create('omi_quotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('omi_zone_id')->constrained()->cascadeOnDelete();
            $table->string('period_label')->nullable();
            $table->string('property_type')->nullable();
            $table->decimal('min_value', 14, 2)->nullable();
            $table->decimal('max_value', 14, 2)->nullable();
            $table->timestamps();
        });

        Postgis::uniqueIndex(
            'omi_quotations_natural_key_unique',
            'omi_quotations',
            "omi_zone_id, (COALESCE(period_label, '')), (COALESCE(property_type, ''))",
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('omi_quotations');
        Schema::dropIfExists('omi_zones');
        Schema::dropIfExists('territorial_localities');
        Schema::dropIfExists('business_locations');
        Schema::dropIfExists('address_points');
        Schema::dropIfExists('geographic_zones');
        Schema::dropIfExists('functional_lots');
        Schema::dropIfExists('housing_contexts');
    }
};
