<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * National list of the Comuni (ISTAT), reference data only: used to suggest name, code and province.
     * Kept apart from `municipalities`, which holds the Comuni the platform works with (catalogue, census, zones).
     */
    public function up(): void
    {
        Schema::create('territorial_municipalities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('territorial_province_id')->nullable()->constrained()->nullOnDelete();
            $table->string('istat_code', 6)->unique();
            $table->string('cadastral_code', 4)->unique();
            $table->string('name');
            $table->timestamps();

            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('territorial_municipalities');
    }
};
