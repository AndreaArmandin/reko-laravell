<?php

use App\Support\Postgis;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only audit trail (TS Audit {actorId, action, entityId, at, reason, before, after}).
     * - agency_id NULL only for platform events; RESTRICT so an audited agency cannot be deleted.
     * - user_id SET NULL keeps the row when an account is deleted. The trigger allows exactly
     *   that update and nothing else: rows can be inserted, never changed or removed.
     * - before/after and any extra detail go in payload; reason is a column because some
     *   gestionale actions require it (reopen, price change, census corrections).
     */
    public function up(): void
    {
        Postgis::assertPgsql();

        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 80);
            $table->string('auditable_type', 60)->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->text('reason')->nullable();
            $table->jsonb('payload')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['agency_id', 'occurred_at']);
            $table->index(['auditable_type', 'auditable_id']);
        });

        Postgis::check('audit_events', 'audit_events_action_check', "action ~ '^[a-z][a-z0-9_-]*(\\.[a-z0-9_-]+)+$'");
        Postgis::check('audit_events', 'audit_events_auditable_check', '(auditable_type IS NULL) = (auditable_id IS NULL)');
        Postgis::check('audit_events', 'audit_events_payload_check', "payload IS NULL OR jsonb_typeof(payload) = 'object'");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION reko_audit_events_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'UPDATE'
                    AND OLD.user_id IS NOT NULL AND NEW.user_id IS NULL
                    AND (to_jsonb(NEW) - 'user_id') = (to_jsonb(OLD) - 'user_id') THEN
                    RETURN NEW;
                END IF;
                RAISE EXCEPTION 'audit_events is append-only (% refused)', TG_OP USING ERRCODE = 'restrict_violation';
            END;
            $$;

            CREATE TRIGGER audit_events_append_only
                BEFORE UPDATE OR DELETE ON audit_events
                FOR EACH ROW EXECUTE FUNCTION reko_audit_events_append_only();

            CREATE TRIGGER audit_events_no_truncate
                BEFORE TRUNCATE ON audit_events
                FOR EACH STATEMENT EXECUTE FUNCTION reko_audit_events_append_only();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
        DB::unprepared('DROP FUNCTION IF EXISTS reko_audit_events_append_only()');
    }
};
