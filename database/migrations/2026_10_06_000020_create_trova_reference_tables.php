<?php

use App\Support\Postgis;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Postgis::assertPgsql();

        Schema::create('geographic_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name');
            $table->string('kind', 30);
            $table->boolean('indicative')->default(false);
            $table->text('notice')->nullable();
            $table->string('source', 255);
            $table->timestamps();
            $table->unique(['municipality_id', 'code']);
        });
        Postgis::addGeometry('geographic_zones', 'boundary', 'MultiPolygon');
        Postgis::gist('geographic_zones', 'boundary');

        Schema::create('omi_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name');
            $table->string('band', 20)->nullable();
            $table->string('semester', 10);
            $table->text('source_url')->nullable();
            $table->timestamps();
            $table->unique(['municipality_id', 'semester', 'code']);
        });
        Postgis::addGeometry('omi_zones', 'boundary', 'MultiPolygon');
        Postgis::gist('omi_zones', 'boundary');

        Schema::create('omi_quotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('omi_zone_id')->constrained()->cascadeOnDelete();
            $table->string('category', 30);
            $table->jsonb('reference')->nullable();
            $table->jsonb('rows');
            $table->text('source_url')->nullable();
            $table->timestamps();
            $table->unique(['omi_zone_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('omi_quotations');
        Schema::dropIfExists('omi_zones');
        Schema::dropIfExists('geographic_zones');
    }
};
