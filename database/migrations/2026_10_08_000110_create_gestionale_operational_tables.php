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

        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('agent_user_id');
            $table->foreignId('municipality_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('code', 40)->nullable();
            $table->string('status', 30)->default('Attivo');
            $table->string('address')->nullable();
            $table->string('civic', 40)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('province', 2)->nullable();
            $table->string('postal_code', 12)->nullable();
            $table->string('zone')->nullable();
            $table->decimal('asking_price', 14, 2)->nullable();
            $table->jsonb('features')->default(DB::raw("'{}'::jsonb"));
            $table->text('description')->nullable();
            $table->text('strengths')->nullable();
            $table->text('internal_notes')->nullable();
            $table->jsonb('mandate')->default(DB::raw("'{}'::jsonb"));
            $table->jsonb('publication')->default(DB::raw("'{}'::jsonb"));
            $table->string('lifecycle_state', 10)->nullable();
            $table->timestamp('lifecycle_at')->nullable();
            $table->foreignId('lifecycle_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('lifecycle_reason')->nullable();
            $table->jsonb('assigned_scout_user_ids')->default(DB::raw("'[]'::jsonb"));
            $table->timestamp('acquired_at')->nullable();
            $table->foreignId('acquired_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps(6);

            $table->foreign('agency_id')->references('id')->on('agencies')->restrictOnDelete();
            $table->foreign(['agency_id', 'agent_user_id'])->references(['agency_id', 'user_id'])->on('agency_memberships')->restrictOnDelete();
            $table->unique(['agency_id', 'id']);
            $table->index(['agency_id', 'status']);
            $table->index(['agency_id', 'updated_at']);
        });
        Postgis::addGeometry('properties', 'location', 'Point');
        Postgis::gist('properties', 'location');
        Postgis::check('properties', 'properties_features_check', "jsonb_typeof(features) = 'object' AND jsonb_typeof(mandate) = 'object' AND jsonb_typeof(publication) = 'object' AND jsonb_typeof(assigned_scout_user_ids) = 'array'");

        Schema::create('property_units', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('property_id');
            $table->foreignId('cadastral_unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('role')->nullable();
            $table->timestamps();
            $table->foreign(['agency_id', 'property_id'])->references(['agency_id', 'id'])->on('properties')->cascadeOnDelete();
            $table->unique(['property_id', 'cadastral_unit_id']);
        });

        Schema::create('property_contacts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('property_id');
            $table->unsignedBigInteger('contact_id');
            $table->string('role');
            $table->timestamps();
            $table->foreign(['agency_id', 'property_id'])->references(['agency_id', 'id'])->on('properties')->cascadeOnDelete();
            $table->foreign(['agency_id', 'contact_id'])->references(['agency_id', 'id'])->on('contacts')->restrictOnDelete();
            $table->unique(['property_id', 'contact_id', 'role']);
        });

        Schema::create('property_matches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('property_id');
            $table->unsignedBigInteger('property_request_id');
            $table->decimal('score', 8, 4)->nullable();
            $table->jsonb('result')->default(DB::raw("'{}'::jsonb"));
            $table->string('status', 40)->default('Nuovo abbinamento');
            $table->text('feedback')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('note')->nullable();
            $table->text('next_action')->nullable();
            $table->timestamp('visit_at')->nullable();
            $table->timestamps(6);
            $table->foreign(['agency_id', 'property_id'])->references(['agency_id', 'id'])->on('properties')->cascadeOnDelete();
            $table->foreign(['agency_id', 'property_request_id'])->references(['agency_id', 'id'])->on('property_requests')->cascadeOnDelete();
            $table->unique(['property_id', 'property_request_id']);
        });
        Postgis::check('property_matches', 'property_matches_result_check', "jsonb_typeof(result) = 'object'");

        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('assigned_to_user_id')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('property_id')->nullable();
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->unsignedBigInteger('property_request_id')->nullable();
            $table->foreignId('parcel_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 50);
            $table->string('subject');
            $table->string('status', 40)->default('Da svolgere');
            $table->string('priority', 10)->default('Normale');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('outcome_confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('outcome')->nullable();
            $table->string('visibility', 20)->default('participants');
            $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
            $table->text('notes')->nullable();
            $table->timestamps(6);
            $table->foreign('agency_id')->references('id')->on('agencies')->restrictOnDelete();
            $table->foreign(['agency_id', 'assigned_to_user_id'])->references(['agency_id', 'user_id'])->on('agency_memberships')->restrictOnDelete();
            $table->foreign(['agency_id', 'property_id'])->references(['agency_id', 'id'])->on('properties')->restrictOnDelete();
            $table->foreign(['agency_id', 'contact_id'])->references(['agency_id', 'id'])->on('contacts')->restrictOnDelete();
            $table->foreign(['agency_id', 'property_request_id'])->references(['agency_id', 'id'])->on('property_requests')->restrictOnDelete();
            $table->unique(['agency_id', 'id']);
            $table->index(['agency_id', 'scheduled_at', 'status']);
        });
        Postgis::check('activities', 'activities_metadata_check', "jsonb_typeof(metadata) = 'object'");

        Schema::create('activity_participants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('activity_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->timestamps();
            $table->foreign(['agency_id', 'activity_id'])->references(['agency_id', 'id'])->on('activities')->cascadeOnDelete();
            $table->foreign(['agency_id', 'user_id'])->references(['agency_id', 'user_id'])->on('agency_memberships')->restrictOnDelete();
            $table->foreign(['agency_id', 'contact_id'])->references(['agency_id', 'id'])->on('contacts')->restrictOnDelete();
        });
        Postgis::check('activity_participants', 'activity_participants_party_check', 'user_id IS NOT NULL OR contact_id IS NOT NULL');

        Schema::create('activity_units', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('activity_id');
            $table->foreignId('cadastral_unit_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->foreign(['agency_id', 'activity_id'])->references(['agency_id', 'id'])->on('activities')->cascadeOnDelete();
            $table->unique(['activity_id', 'cadastral_unit_id']);
        });

        Schema::create('activity_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('activity_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('kind');
            $table->jsonb('payload')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->foreign(['agency_id', 'activity_id'])->references(['agency_id', 'id'])->on('activities')->cascadeOnDelete();
            $table->foreign(['agency_id', 'user_id'])->references(['agency_id', 'user_id'])->on('agency_memberships')->restrictOnDelete();
        });

        Schema::create('scouting_zones', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('operator_user_id');
            $table->string('name');
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('Pianificata');
            $table->date('starts_on')->nullable();
            $table->jsonb('municipalities')->default(DB::raw("'[]'::jsonb"));
            $table->timestamps();
            $table->foreign('agency_id')->references('id')->on('agencies')->restrictOnDelete();
            $table->foreign(['agency_id', 'operator_user_id'])->references(['agency_id', 'user_id'])->on('agency_memberships')->restrictOnDelete();
            $table->unique(['agency_id', 'id']);
        });
        Postgis::addGeometry('scouting_zones', 'boundary', 'MultiPolygon');
        Postgis::gist('scouting_zones', 'boundary');
        Postgis::check('scouting_zones', 'scouting_zones_municipalities_check', "jsonb_typeof(municipalities) = 'array'");

        Schema::create('scouting_zone_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('scouting_zone_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamps();
            $table->foreign(['agency_id', 'scouting_zone_id'])->references(['agency_id', 'id'])->on('scouting_zones')->cascadeOnDelete();
            $table->foreign(['agency_id', 'user_id'])->references(['agency_id', 'user_id'])->on('agency_memberships')->restrictOnDelete();
            $table->unique(['scouting_zone_id', 'user_id']);
        });

        Schema::create('scouting_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('user_id');
            $table->foreignId('cadastral_unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('parcel_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('scouting_zone_id')->nullable();
            $table->string('status', 30)->default('Da contattare');
            $table->date('due_on')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->foreign('agency_id')->references('id')->on('agencies')->restrictOnDelete();
            $table->foreign(['agency_id', 'user_id'])->references(['agency_id', 'user_id'])->on('agency_memberships')->restrictOnDelete();
            $table->foreign(['agency_id', 'scouting_zone_id'])->references(['agency_id', 'id'])->on('scouting_zones')->restrictOnDelete();
        });

        Schema::create('agency_unit_observations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->foreignId('cadastral_unit_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->date('observed_on')->nullable();
            $table->text('condition_notes')->nullable();
            $table->decimal('asking_price', 14, 2)->nullable();
            $table->string('occupancy')->nullable();
            $table->jsonb('data')->default(DB::raw("'{}'::jsonb"));
            $table->timestamps();
            $table->foreign('agency_id')->references('id')->on('agencies')->restrictOnDelete();
            $table->foreign(['agency_id', 'user_id'])->references(['agency_id', 'user_id'])->on('agency_memberships')->restrictOnDelete();
        });

        Schema::create('ownerships', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->foreignId('cadastral_unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('parcel_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->integer('share_numerator')->nullable();
            $table->integer('share_denominator')->nullable();
            $table->string('right_type')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->string('source')->nullable();
            $table->jsonb('details')->default(DB::raw("'{}'::jsonb"));
            $table->timestamps();
            $table->foreign('agency_id')->references('id')->on('agencies')->restrictOnDelete();
            $table->foreign(['agency_id', 'contact_id'])->references(['agency_id', 'id'])->on('contacts')->restrictOnDelete();
        });
        Postgis::check('ownerships', 'ownerships_subject_present_check', 'parcel_id IS NOT NULL OR cadastral_unit_id IS NOT NULL');
        Postgis::check('ownerships', 'ownerships_share_denominator_check', 'share_denominator IS NULL OR share_denominator > 0');
        Postgis::check('ownerships', 'ownerships_share_numerator_check', 'share_numerator IS NULL OR share_numerator >= 0');

        Schema::create('census_proposals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->foreignId('cadastral_unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('parcel_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('proposed_by_user_id')->nullable();
            $table->string('status', 20)->default('Da verificare');
            $table->jsonb('payload')->default(DB::raw("'{}'::jsonb"));
            $table->text('notes')->nullable();
            $table->jsonb('review')->nullable();
            $table->timestamps();
            $table->foreign('agency_id')->references('id')->on('agencies')->restrictOnDelete();
            $table->foreign(['agency_id', 'proposed_by_user_id'])->references(['agency_id', 'user_id'])->on('agency_memberships')->restrictOnDelete();
            $table->index(['agency_id', 'status']);
        });

        Schema::create('census_batches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('actor_user_id');
            $table->unsignedBigInteger('scouting_zone_id')->nullable();
            $table->string('source', 40)->default('Sister');
            $table->date('source_date')->nullable();
            $table->string('fingerprint', 64)->nullable();
            $table->jsonb('summary')->default(DB::raw("'{}'::jsonb"));
            $table->jsonb('issues')->default(DB::raw("'[]'::jsonb"));
            $table->timestamps();
            $table->foreign('agency_id')->references('id')->on('agencies')->restrictOnDelete();
            $table->foreign(['agency_id', 'actor_user_id'])->references(['agency_id', 'user_id'])->on('agency_memberships')->restrictOnDelete();
            $table->foreign(['agency_id', 'scouting_zone_id'])->references(['agency_id', 'id'])->on('scouting_zones')->restrictOnDelete();
            $table->unique(['agency_id', 'fingerprint']);
        });

        Schema::create('census_saved_filters', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('user_id');
            $table->string('name');
            $table->string('view', 30)->default('Immobili');
            $table->string('search', 300)->nullable();
            $table->jsonb('filters')->default(DB::raw("'{}'::jsonb"));
            $table->timestamps();
            $table->foreign('agency_id')->references('id')->on('agencies')->restrictOnDelete();
            $table->foreign(['agency_id', 'user_id'])->references(['agency_id', 'user_id'])->on('agency_memberships')->cascadeOnDelete();
        });

        Schema::create('acquisitions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('property_id')->nullable();
            $table->string('title');
            $table->string('status', 30);
            $table->date('opened_on')->nullable();
            $table->date('closed_on')->nullable();
            $table->text('notes')->nullable();
            $table->jsonb('details')->default(DB::raw("'{}'::jsonb"));
            $table->timestamps();
            $table->foreign('agency_id')->references('id')->on('agencies')->restrictOnDelete();
            $table->foreign(['agency_id', 'property_id'])->references(['agency_id', 'id'])->on('properties')->restrictOnDelete();
            $table->unique(['agency_id', 'id']);
        });

        Schema::create('acquisition_units', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('acquisition_id');
            $table->foreignId('cadastral_unit_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->foreign(['agency_id', 'acquisition_id'])->references(['agency_id', 'id'])->on('acquisitions')->cascadeOnDelete();
            $table->unique(['acquisition_id', 'cadastral_unit_id']);
        });

        Schema::create('acquisition_contacts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('acquisition_id');
            $table->unsignedBigInteger('contact_id');
            $table->string('role');
            $table->timestamps();
            $table->foreign(['agency_id', 'acquisition_id'])->references(['agency_id', 'id'])->on('acquisitions')->cascadeOnDelete();
            $table->foreign(['agency_id', 'contact_id'])->references(['agency_id', 'id'])->on('contacts')->restrictOnDelete();
            $table->unique(['acquisition_id', 'contact_id', 'role']);
        });

        Schema::create('acquisition_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('acquisition_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('kind');
            $table->jsonb('payload')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->foreign(['agency_id', 'acquisition_id'])->references(['agency_id', 'id'])->on('acquisitions')->cascadeOnDelete();
            $table->foreign(['agency_id', 'user_id'])->references(['agency_id', 'user_id'])->on('agency_memberships')->restrictOnDelete();
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('uploaded_by_user_id')->nullable();
            $table->string('title');
            $table->string('storage_path')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('byte_size')->nullable();
            $table->string('documentable_type')->nullable();
            $table->unsignedBigInteger('documentable_id')->nullable();
            $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
            $table->timestamps();
            $table->foreign('agency_id')->references('id')->on('agencies')->restrictOnDelete();
            $table->foreign(['agency_id', 'uploaded_by_user_id'])->references(['agency_id', 'user_id'])->on('agency_memberships')->restrictOnDelete();
            $table->index(['documentable_type', 'documentable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
        Schema::dropIfExists('acquisition_events');
        Schema::dropIfExists('acquisition_contacts');
        Schema::dropIfExists('acquisition_units');
        Schema::dropIfExists('acquisitions');
        Schema::dropIfExists('census_saved_filters');
        Schema::dropIfExists('census_batches');
        Schema::dropIfExists('census_proposals');
        Schema::dropIfExists('ownerships');
        Schema::dropIfExists('agency_unit_observations');
        Schema::dropIfExists('scouting_assignments');
        Schema::dropIfExists('scouting_zone_assignments');
        Schema::dropIfExists('scouting_zones');
        Schema::dropIfExists('activity_events');
        Schema::dropIfExists('activity_units');
        Schema::dropIfExists('activity_participants');
        Schema::dropIfExists('activities');
        Schema::dropIfExists('property_matches');
        Schema::dropIfExists('property_contacts');
        Schema::dropIfExists('property_units');
        Schema::dropIfExists('properties');
    }
};
