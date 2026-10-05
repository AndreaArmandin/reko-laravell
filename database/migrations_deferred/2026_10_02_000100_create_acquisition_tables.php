<?php

use App\Support\Postgis;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agency acquisitions.
     */
    public function up(): void
    {
        Postgis::assertPgsql();

        Schema::create('acquisitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('status');
            $table->date('opened_on')->nullable();
            $table->date('closed_on')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('acquisition_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('acquisition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cadastral_unit_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->unique(['acquisition_id', 'cadastral_unit_id']);
        });

        Schema::create('acquisition_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('acquisition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->timestamps();

            $table->unique(['acquisition_id', 'contact_id', 'role']);
        });

        Schema::create('acquisition_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('acquisition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind');
            $table->jsonb('payload')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('acquisition_events');
        Schema::dropIfExists('acquisition_contacts');
        Schema::dropIfExists('acquisition_units');
        Schema::dropIfExists('acquisitions');
    }
};
