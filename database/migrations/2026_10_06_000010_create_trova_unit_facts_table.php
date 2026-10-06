<?php

use App\Support\Postgis;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Facts derived per unit and release, like Trova's precomputed housing search index:
     * the floors written in the address and the v4 classification (apartment / independent house).
     * Rebuilt with `php artisan trova:unit-facts`. Never a replacement for cadastral records.
     */
    public function up(): void
    {
        Postgis::assertPgsql();

        Schema::create('trova_unit_facts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_release_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cadastral_unit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parcel_id')->constrained()->cascadeOnDelete();
            // v4 levels of the unit; parcel top floor of its homes (null when any home floor is unknown)
            $table->smallInteger('v4_highest')->nullable();
            $table->smallInteger('parcel_top_floor')->nullable();
            // v4 classification, only for homes A/1–A/9
            $table->string('housing_outcome', 20)->nullable();
            $table->string('housing_confidence', 30)->nullable();
            $table->string('housing_levels', 20)->nullable();
            $table->jsonb('housing_notes')->nullable();
            $table->boolean('vertical_independent')->default(false);
            // Whole parcel has at least two distinct homes (apartment search)
            $table->boolean('apartment_parcel')->default(false);

            $table->unique(['catalog_release_id', 'cadastral_unit_id']);
            $table->index(['catalog_release_id', 'housing_outcome']);
            $table->index(['catalog_release_id', 'parcel_id']);
        });

        // floor_levels: residential parser of housing-context.ts (garage exact floor, floor choices); v4_levels: housing-v4.ts
        DB::statement('ALTER TABLE trova_unit_facts ADD COLUMN floor_levels smallint[], ADD COLUMN v4_levels smallint[]');

        Postgis::check('trova_unit_facts', 'trova_unit_facts_outcome_check',
            "housing_outcome IS NULL OR housing_outcome IN ('CASA INDIPENDENTE', 'APPARTAMENTO', 'DA VERIFICARE')");
    }

    public function down(): void
    {
        Schema::dropIfExists('trova_unit_facts');
    }
};
