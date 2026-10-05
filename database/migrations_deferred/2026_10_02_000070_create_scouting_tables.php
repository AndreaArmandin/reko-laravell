<?php

use App\Support\Postgis;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agency scouting zones and assignments.
     */
    public function up(): void
    {
        Postgis::assertPgsql();

        Schema::create('scouting_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Postgis::addGeometry('scouting_zones', 'boundary', 'MultiPolygon');
        Postgis::gist('scouting_zones', 'boundary');

        Schema::create('scouting_zone_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scouting_zone_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['scouting_zone_id', 'user_id']);
        });

        Schema::create('scouting_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cadastral_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('parcel_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('scouting_zone_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status');
            $table->date('due_on')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scouting_assignments');
        Schema::dropIfExists('scouting_zone_assignments');
        Schema::dropIfExists('scouting_zones');
    }
};
