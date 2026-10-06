<?php

use App\Support\Postgis;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agency observations, recorded ownership, and census proposals.
     * contacts, contact_channels and client_profiles are active since
     * database/migrations/2026_10_08_000060_create_contact_tables.php (rewritten, composite FKs).
     * DRAFT: when activated, rewrite as a new dated migration with agency_id RESTRICT and
     * composite (agency_id, contact_id) FKs; do not move this file.
     */
    public function up(): void
    {
        Postgis::assertPgsql();

        Schema::create('agency_unit_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cadastral_unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->date('observed_on')->nullable();
            $table->text('condition_notes')->nullable();
            $table->decimal('asking_price', 14, 2)->nullable();
            $table->string('occupancy')->nullable();
            $table->timestamps();
        });

        Schema::create('ownerships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cadastral_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('parcel_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('share_numerator')->nullable();
            $table->integer('share_denominator')->nullable();
            $table->string('right_type')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->string('source')->nullable();
            $table->timestamps();
        });

        Postgis::check(
            'ownerships',
            'ownerships_subject_present_check',
            'parcel_id IS NOT NULL OR cadastral_unit_id IS NOT NULL',
        );
        Postgis::check(
            'ownerships',
            'ownerships_share_denominator_check',
            'share_denominator IS NULL OR share_denominator > 0',
        );
        Postgis::check(
            'ownerships',
            'ownerships_share_numerator_check',
            'share_numerator IS NULL OR share_numerator >= 0',
        );

        Schema::create('census_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cadastral_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('parcel_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('proposed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status');
            $table->jsonb('payload')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('census_proposals');
        Schema::dropIfExists('ownerships');
        Schema::dropIfExists('agency_unit_observations');
    }
};
