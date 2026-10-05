<?php

use App\Support\Postgis;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Derived housing context of all residential units of a parcel (Trova reko_catalog_housing_contexts).
     * Never a replacement for cadastral records. The generation is the digest of source and rules:
     * a release activates exactly one immutable generation.
     */
    public function up(): void
    {
        Postgis::assertPgsql();

        Schema::create('parcel_housing_contexts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_release_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parcel_id')->constrained()->cascadeOnDelete();
            $table->char('generation', 64);
            $table->unsignedInteger('known_units');
            $table->unsignedInteger('unknown_units');
            $table->string('housing_context');
            $table->smallInteger('top_floor')->nullable();
            $table->timestamps();

            $table->unique(['catalog_release_id', 'generation', 'parcel_id'], 'parcel_housing_contexts_identity_unique');
            $table->index(['catalog_release_id', 'generation', 'known_units', 'unknown_units'], 'parcel_housing_contexts_count_index');
        });

        Postgis::check('parcel_housing_contexts', 'parcel_housing_contexts_context_check',
            "housing_context IN ('single', 'two-family', 'terraced', 'apartments', 'unknown')");
        Postgis::check('parcel_housing_contexts', 'parcel_housing_contexts_counts_check',
            'known_units >= 0 AND unknown_units >= 0');
        Postgis::check('parcel_housing_contexts', 'parcel_housing_contexts_top_floor_check',
            'top_floor IS NULL OR top_floor >= 0');
    }

    public function down(): void
    {
        Schema::dropIfExists('parcel_housing_contexts');
    }
};
