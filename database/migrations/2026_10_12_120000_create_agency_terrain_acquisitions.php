<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agency_terrain_acquisitions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->foreignId('parcel_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('catalog_release_id');
            $table->foreignId('acquired_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acquired_at', 6);
            $table->decimal('area_sqm', 16, 2)->nullable();
            $table->timestamps(6);
            $table->foreign('agency_id')->references('id')->on('agencies')->restrictOnDelete();
            $table->foreign('catalog_release_id')->references('id')->on('catalog_releases')->restrictOnDelete();
            $table->unique(['agency_id', 'parcel_id']);
            $table->index(['agency_id', 'acquired_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_terrain_acquisitions');
    }
};
