<?php

use App\Support\Postgis;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agency CRM contacts, observations, recorded ownership, and census proposals.
     */
    public function up(): void
    {
        Postgis::assertPgsql();

        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->string('display_name');
            $table->string('given_name')->nullable();
            $table->string('family_name')->nullable();
            $table->string('tax_code')->nullable();
            $table->string('vat_number')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Postgis::uniqueIndex(
            'contacts_agency_tax_code_unique',
            'contacts',
            'agency_id, tax_code',
            'tax_code IS NOT NULL',
        );

        Schema::create('contact_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->string('value');
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });

        Schema::create('client_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('kind')->nullable();
            $table->decimal('budget_min', 14, 2)->nullable();
            $table->decimal('budget_max', 14, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

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
        Schema::dropIfExists('client_profiles');
        Schema::dropIfExists('contact_channels');
        Schema::dropIfExists('contacts');
    }
};
