<?php

use App\Support\Postgis;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Postgis::assertPgsql();

        Schema::create('parcels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->restrictOnDelete();
            $table->char('cadastral_kind', 1); // F = fabbricati, T = terreni
            $table->string('section')->default('');
            $table->string('sheet');
            $table->string('number');
            $table->timestamps();

            $table->unique(['municipality_id', 'cadastral_kind', 'section', 'sheet', 'number'], 'parcels_natural_key_unique');
        });
        DB::statement("ALTER TABLE parcels ADD CONSTRAINT parcels_kind_check CHECK (cadastral_kind IN ('F', 'T'))");

        Schema::create('cadastral_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parcel_id')->constrained()->restrictOnDelete();
            $table->string('subalterno')->nullable();
            $table->string('source_ref')->nullable();
            // Original catalogue key (e.g. ["D730","Fabbricati","","1","10","1"]), used to relink CRM records.
            $table->text('legacy_key')->nullable()->unique();
            $table->timestamps();
        });

        // A source reference distinguishes separate records without a subalterno.
        // The generated key cannot disagree with the stored identity fields.
        DB::statement("ALTER TABLE cadastral_units ADD COLUMN identity_key text GENERATED ALWAYS AS (CASE WHEN subalterno IS NOT NULL THEN 's:' || subalterno ELSE 'n:' || source_ref END) STORED");
        DB::statement("ALTER TABLE cadastral_units ADD CONSTRAINT cadastral_units_identity_check CHECK ((subalterno IS NOT NULL AND length(trim(subalterno)) > 0) OR (subalterno IS NULL AND source_ref IS NOT NULL AND length(trim(source_ref)) > 0))");
        DB::statement('CREATE UNIQUE INDEX cadastral_units_identity_unique ON cadastral_units (parcel_id, identity_key)');

        Schema::create('buildings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->restrictOnDelete();
            $table->string('cadastral_building_id')->nullable();
            $table->timestamps();
        });

        Postgis::uniqueIndex(
            'buildings_cadastral_id_unique',
            'buildings',
            'municipality_id, cadastral_building_id',
            'cadastral_building_id IS NOT NULL',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('buildings');
        Schema::dropIfExists('cadastral_units');
        Schema::dropIfExists('parcels');
    }
};
