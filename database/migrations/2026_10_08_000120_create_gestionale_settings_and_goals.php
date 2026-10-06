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

        Schema::table('agency_memberships', function (Blueprint $table) {
            $table->string('group_name')->nullable();
            $table->string('branch_name')->nullable();
            $table->jsonb('catalog_package')->nullable();
        });
        DB::statement(<<<'SQL'
            ALTER TABLE agency_memberships ADD CONSTRAINT agency_memberships_catalog_package_check CHECK (
                catalog_package IS NULL OR (
                    jsonb_typeof(catalog_package) = 'object'
                    AND (catalog_package->>'enabled') IN ('true', 'false')
                    AND (catalog_package->>'maxParcels') ~ '^[0-9]+$'
                )
            )
        SQL);

        Schema::create('gestionale_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->unique();
            $table->jsonb('matching_weights')->default(DB::raw("'{}'::jsonb"));
            $table->unsignedSmallInteger('contact_days')->default(7);
            $table->unsignedSmallInteger('retention_days')->default(180);
            $table->timestamps();
            $table->foreign('agency_id')->references('id')->on('agencies')->restrictOnDelete();
        });
        Postgis::check('gestionale_settings', 'gestionale_settings_weights_check', "jsonb_typeof(matching_weights) = 'object'");

        Schema::create('goals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->string('key', 80);
            $table->boolean('read_only')->default(false);
            $table->timestamps();
            $table->foreign('agency_id')->references('id')->on('agencies')->restrictOnDelete();
            $table->unique(['agency_id', 'id']);
            $table->unique(['agency_id', 'key']);
        });

        Schema::create('goal_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('goal_id');
            $table->unsignedInteger('version');
            $table->date('effective_from');
            $table->jsonb('definition');
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->foreign(['agency_id', 'goal_id'])->references(['agency_id', 'id'])->on('goals')->restrictOnDelete();
            $table->unique(['agency_id', 'id']);
            $table->unique(['goal_id', 'version']);
            $table->index(['agency_id', 'effective_from']);
        });
        Postgis::check('goal_versions', 'goal_versions_definition_check', "jsonb_typeof(definition) = 'object' AND version >= 1");

        Schema::create('goal_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('activity_id');
            $table->unsignedBigInteger('operator_user_id');
            $table->string('subject');
            $table->unsignedBigInteger('property_id')->nullable();
            $table->unsignedBigInteger('parcel_id')->nullable();
            $table->timestamp('occurred_at');
            $table->jsonb('event_types')->default(DB::raw("'[]'::jsonb"));
            $table->string('outcome')->nullable();
            $table->boolean('answered')->default(false);
            $table->boolean('completed')->default(false);
            $table->decimal('amount', 14, 2)->default(0);
            $table->boolean('cancelled')->default(false);
            $table->string('appointment_key')->nullable();
            $table->timestamps();
            $table->foreign(['agency_id', 'activity_id'])->references(['agency_id', 'id'])->on('activities')->cascadeOnDelete();
            $table->foreign(['agency_id', 'operator_user_id'])->references(['agency_id', 'user_id'])->on('agency_memberships')->restrictOnDelete();
            $table->foreign(['agency_id', 'property_id'])->references(['agency_id', 'id'])->on('properties')->restrictOnDelete();
            $table->unique(['agency_id', 'id']);
            $table->index(['agency_id', 'occurred_at']);
        });
        Postgis::check('goal_events', 'goal_events_types_check', "jsonb_typeof(event_types) = 'array'");

        Schema::create('goal_ledger', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('goal_id');
            $table->unsignedBigInteger('goal_version_id');
            $table->unsignedBigInteger('goal_event_id');
            $table->unsignedBigInteger('operator_user_id');
            $table->string('subject');
            $table->unsignedBigInteger('property_id')->nullable();
            $table->timestamp('occurred_at');
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('value', 14, 2)->default(0);
            $table->text('reason');
            $table->jsonb('rule')->default(DB::raw("'{}'::jsonb"));
            $table->timestamps();
            $table->foreign(['agency_id', 'goal_id'])->references(['agency_id', 'id'])->on('goals')->restrictOnDelete();
            $table->foreign(['agency_id', 'goal_version_id'])->references(['agency_id', 'id'])->on('goal_versions')->restrictOnDelete();
            $table->foreign(['agency_id', 'goal_event_id'])->references(['agency_id', 'id'])->on('goal_events')->restrictOnDelete();
            $table->foreign(['agency_id', 'operator_user_id'])->references(['agency_id', 'user_id'])->on('agency_memberships')->restrictOnDelete();
            $table->foreign(['agency_id', 'property_id'])->references(['agency_id', 'id'])->on('properties')->restrictOnDelete();
            $table->index(['agency_id', 'goal_id', 'period_start']);
        });
        Postgis::check('goal_ledger', 'goal_ledger_rule_check', "jsonb_typeof(rule) = 'object'");

        Schema::create('gestionale_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('activity_id')->nullable();
            $table->unsignedBigInteger('acquisition_id')->nullable();
            $table->string('title');
            $table->timestamp('occurred_at');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->foreign(['agency_id', 'user_id'])->references(['agency_id', 'user_id'])->on('agency_memberships')->cascadeOnDelete();
            $table->foreign(['agency_id', 'activity_id'])->references(['agency_id', 'id'])->on('activities')->cascadeOnDelete();
            $table->foreign(['agency_id', 'acquisition_id'])->references(['agency_id', 'id'])->on('acquisitions')->cascadeOnDelete();
            $table->index(['agency_id', 'user_id', 'read_at', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gestionale_notifications');
        Schema::dropIfExists('goal_ledger');
        Schema::dropIfExists('goal_events');
        Schema::dropIfExists('goal_versions');
        Schema::dropIfExists('goals');
        Schema::dropIfExists('gestionale_settings');
        DB::statement('ALTER TABLE agency_memberships DROP CONSTRAINT IF EXISTS agency_memberships_catalog_package_check');
        Schema::table('agency_memberships', function (Blueprint $table) {
            $table->dropColumn(['group_name', 'branch_name', 'catalog_package']);
        });
    }
};
