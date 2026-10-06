<?php

use App\Support\Postgis;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Client search requests (gestionale Request), rewriting the property_requests draft.
     * - "One request per client" is enforced in the app with row locks (SavePropertyRequest), not with
     *   a UNIQUE: legacy data may hold more than one request per client (TS seed c1 → r1, r9).
     * - criteria / classifications: questionnaire answers keyed by question id (single source of truth
     *   for budget, zones, …); matching (later phase) may add generated columns.
     * - agent_user_id is always a copy of the client's referent (SaveClient / SavePropertyRequest keep it in sync).
     * - priority, priority_reason, next_action, due_date are legacy, read-only (no command writes them).
     * - updated_at has microsecond precision: it is the optimistic revision (expected_updated_at).
     */
    public function up(): void
    {
        Postgis::assertPgsql();

        Schema::create('property_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('contact_id');
            $table->unsignedBigInteger('agent_user_id');
            $table->text('title');
            $table->boolean('title_auto')->default(false);
            $table->string('status', 30)->default('Nuova');
            $table->jsonb('criteria')->default(DB::raw("'{}'::jsonb"));
            $table->jsonb('classifications')->default(DB::raw("'{}'::jsonb"));
            $table->string('step_id', 100)->default('operation');
            $table->boolean('finished')->default(false);
            $table->string('priority', 10)->default('Normale');
            $table->text('priority_reason')->nullable();
            $table->text('next_action')->nullable();
            $table->date('due_date')->nullable();
            $table->string('lifecycle_state', 10)->nullable();
            $table->timestamp('lifecycle_at')->nullable();
            $table->foreignId('lifecycle_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('lifecycle_reason')->nullable();
            $table->timestamps(6);

            $table->foreign('agency_id')->references('id')->on('agencies')->restrictOnDelete();
            $table->foreign(['agency_id', 'contact_id'], 'property_requests_contact_fk')
                ->references(['agency_id', 'id'])->on('contacts')->restrictOnDelete();
            $table->foreign('contact_id', 'property_requests_client_profile_fk')
                ->references('contact_id')->on('client_profiles')->restrictOnDelete();
            $table->foreign(['agency_id', 'agent_user_id'], 'property_requests_agent_membership_fk')
                ->references(['agency_id', 'user_id'])->on('agency_memberships')->restrictOnDelete();
            $table->unique(['agency_id', 'id'], 'property_requests_agency_id_id_unique');
            $table->index(['agency_id', 'contact_id']);
            $table->index(['agency_id', 'agent_user_id']);
            $table->index(['agency_id', 'status']);
        });

        DB::statement('CREATE INDEX property_requests_recent_index ON property_requests (agency_id, updated_at DESC, id)');

        Postgis::check('property_requests', 'property_requests_status_check',
            "status IN ('Nuova', 'Da completare', 'Profilata', 'Ricerca attiva', 'Immobili individuati', 'Immobili proposti', 'Visita programmata', 'In trattativa', 'Sospesa', 'Conclusa', 'Annullata')");
        Postgis::check('property_requests', 'property_requests_priority_check', "priority IN ('Normale', 'Alta', 'Urgente')");
        Postgis::check('property_requests', 'property_requests_json_check',
            "jsonb_typeof(criteria) = 'object' AND jsonb_typeof(classifications) = 'object'");
        Postgis::check('property_requests', 'property_requests_lifecycle_check',
            "(lifecycle_state IS NULL AND lifecycle_at IS NULL) OR (lifecycle_state IN ('archived', 'removed') AND lifecycle_at IS NOT NULL)");
    }

    public function down(): void
    {
        Schema::dropIfExists('property_requests');
    }
};
