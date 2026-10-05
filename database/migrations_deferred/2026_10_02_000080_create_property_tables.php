<?php

use App\Support\Postgis;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agency properties, mandates, requests, question sets, and matches.
     */
    public function up(): void
    {
        Postgis::assertPgsql();

        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('municipality_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('status');
            $table->decimal('asking_price', 14, 2)->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Postgis::addGeometry('properties', 'location', 'Point');
        Postgis::gist('properties', 'location');

        Schema::create('property_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cadastral_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('role')->nullable();
            $table->timestamps();
        });

        Postgis::uniqueIndex(
            'property_units_property_unit_unique',
            'property_units',
            'property_id, cadastral_unit_id',
            'cadastral_unit_id IS NOT NULL',
        );

        Schema::create('property_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->timestamps();

            $table->unique(['property_id', 'contact_id', 'role']);
        });

        Schema::create('mandates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind');
            $table->string('status');
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->decimal('commission_rate', 8, 4)->nullable();
            $table->timestamps();
        });

        Schema::create('property_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('status');
            $table->decimal('budget_min', 14, 2)->nullable();
            $table->decimal('budget_max', 14, 2)->nullable();
            $table->text('notes')->nullable();
            $table->jsonb('criteria')->nullable();
            $table->timestamps();
        });

        Schema::create('question_set_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->unsignedInteger('version');
            $table->jsonb('questions');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Postgis::uniqueIndex(
            'question_set_versions_platform_unique',
            'question_set_versions',
            'code, version',
            'agency_id IS NULL',
        );
        Postgis::uniqueIndex(
            'question_set_versions_agency_unique',
            'question_set_versions',
            'agency_id, code, version',
            'agency_id IS NOT NULL',
        );

        Schema::create('property_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_request_id')->constrained()->cascadeOnDelete();
            $table->decimal('score', 8, 4)->nullable();
            $table->string('status');
            $table->timestamps();

            $table->unique(['property_id', 'property_request_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('property_matches');
        Schema::dropIfExists('question_set_versions');
        Schema::dropIfExists('property_requests');
        Schema::dropIfExists('mandates');
        Schema::dropIfExists('property_contacts');
        Schema::dropIfExists('property_units');
        Schema::dropIfExists('properties');
    }
};
