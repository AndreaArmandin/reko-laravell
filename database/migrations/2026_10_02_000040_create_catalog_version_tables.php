<?php

use App\Support\Postgis;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catalog releases, the municipality pointer, and per-release versions.
     * Source measures (area, rendita, geometry) are nullable and have no zero default.
     */
    public function up(): void
    {
        Postgis::assertPgsql();

        Schema::create('catalog_releases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->restrictOnDelete();
            $table->string('code')->unique();
            $table->string('label');
            $table->date('released_on')->nullable();
            $table->string('status');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('municipality_catalogs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('catalog_release_id')->constrained()->restrictOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('parcel_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parcel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('catalog_release_id')->constrained()->restrictOnDelete();
            $table->decimal('area_sqm', 16, 2)->nullable();
            $table->string('quality')->nullable();
            $table->string('cadastral_class')->nullable();
            $table->decimal('income_dominical', 14, 2)->nullable();
            $table->decimal('income_agrarian', 14, 2)->nullable();
            $table->string('partita')->nullable();
            $table->timestamps();

            $table->unique(['parcel_id', 'catalog_release_id']);
        });

        Postgis::addGeometry('parcel_versions', 'boundary', 'MultiPolygon');
        Postgis::gist('parcel_versions', 'boundary');

        Schema::create('cadastral_unit_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cadastral_unit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('catalog_release_id')->constrained()->restrictOnDelete();
            $table->string('status')->default('eligible');
            $table->text('status_reason')->nullable();
            $table->string('category')->nullable();
            $table->string('search_group')->nullable();
            $table->string('class')->nullable();
            $table->decimal('consistency', 12, 2)->nullable();
            $table->string('consistency_unit')->nullable();
            $table->decimal('cadastral_area_sqm', 16, 2)->nullable();
            $table->decimal('rendita', 14, 2)->nullable();
            $table->text('address_raw')->nullable();
            $table->string('address_toponym')->nullable();
            $table->string('address_number')->nullable();
            $table->string('floor')->nullable();
            $table->string('interior')->nullable();
            $table->string('partita')->nullable();
            $table->string('census_zone')->nullable();
            $table->timestamps();

            $table->unique(['cadastral_unit_id', 'catalog_release_id'], 'unit_versions_unit_release_unique');
        });

        Postgis::addGeometry('cadastral_unit_versions', 'location', 'Point');
        Postgis::gist('cadastral_unit_versions', 'location');

        Schema::create('building_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('building_id')->constrained()->cascadeOnDelete();
            $table->foreignId('catalog_release_id')->constrained()->restrictOnDelete();
            $table->decimal('area_sqm', 16, 2)->nullable();
            $table->timestamps();

            $table->unique(['building_id', 'catalog_release_id']);
        });

        Postgis::addGeometry('building_versions', 'footprint', 'MultiPolygon');
        Postgis::gist('building_versions', 'footprint');

        Schema::create('building_parcel_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_release_id')->constrained()->cascadeOnDelete();
            $table->foreignId('building_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parcel_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['catalog_release_id', 'building_id', 'parcel_id'], 'building_parcel_links_release_unique');
        });

        Schema::create('unit_version_floors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cadastral_unit_version_id')->constrained()->cascadeOnDelete();
            $table->string('floor_code')->nullable();
            $table->decimal('area_sqm', 16, 2)->nullable();
            $table->decimal('height_m', 8, 2)->nullable();
            $table->unsignedInteger('sort_order')->nullable();
            $table->timestamps();
        });

        Schema::create('catalog_exclusions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_release_id')->constrained();
            $table->foreignId('municipality_id')->nullable()->constrained()->nullOnDelete();
            $table->string('entity_type');
            $table->string('entity_key');
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->unique(['catalog_release_id', 'entity_type', 'entity_key'], 'catalog_exclusions_entity_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('catalog_exclusions');
        Schema::dropIfExists('unit_version_floors');
        Schema::dropIfExists('building_parcel_links');
        Schema::dropIfExists('building_versions');
        Schema::dropIfExists('cadastral_unit_versions');
        Schema::dropIfExists('parcel_versions');
        Schema::dropIfExists('municipality_catalogs');
        Schema::dropIfExists('catalog_releases');
    }
};
