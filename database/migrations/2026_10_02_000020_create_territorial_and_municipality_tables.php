<?php

use App\Support\Postgis;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Territorial reference and comuni. Boundaries are nullable MultiPolygon 4326.
     */
    public function up(): void
    {
        Postgis::assertPgsql();

        Schema::create('territorial_regions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 2)->unique();
            $table->string('name');
            $table->timestamps();
        });

        Postgis::addGeometry('territorial_regions', 'boundary', 'MultiPolygon');
        Postgis::gist('territorial_regions', 'boundary');

        Schema::create('territorial_provinces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('territorial_region_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 3)->unique();
            $table->string('abbreviation', 2)->nullable()->unique();
            $table->string('name');
            $table->timestamps();
        });

        Postgis::addGeometry('territorial_provinces', 'boundary', 'MultiPolygon');
        Postgis::gist('territorial_provinces', 'boundary');

        Schema::create('municipalities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('territorial_province_id')->nullable()->constrained()->nullOnDelete();
            $table->string('istat_code', 6)->nullable()->unique();
            $table->string('cadastral_code', 4)->unique();
            $table->string('name');
            $table->timestamps();
        });

        Postgis::addGeometry('municipalities', 'boundary', 'MultiPolygon');
        Postgis::gist('municipalities', 'boundary');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('municipalities');
        Schema::dropIfExists('territorial_provinces');
        Schema::dropIfExists('territorial_regions');
    }
};
