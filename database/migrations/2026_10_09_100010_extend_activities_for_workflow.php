<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Agenda del gestionale (types.ts Activity): i campi che l'originale tiene sull'attività e che la tabella
| non aveva. Tipo = kind, titolo = subject, scadenza = scheduled_at, commento = notes, responsabile =
| assigned_to_user_id, creatore = created_by_user_id, cliente = contact_id, condivisioni = activity_participants,
| unità = activity_units, storico delle risposte (responses) = activity_events.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            // ownerId: il proprietario è un contatto dell'agenzia (non un cliente).
            $table->unsignedBigInteger('owner_contact_id')->nullable()->after('contact_id');
            $table->foreignId('outcome_confirmed_by_user_id')->nullable()->after('outcome_confirmed_at')->constrained('users')->nullOnDelete();
            $table->string('origin_role', 10)->nullable();
            $table->boolean('internal')->default(true);
            $table->boolean('answered')->nullable();
            $table->string('contact_operation', 10)->nullable();
            $table->string('crm_operation', 10)->nullable();
            $table->string('crm_category', 40)->nullable();
            $table->string('interest')->nullable();
            $table->string('event_type', 120)->nullable();
            $table->decimal('amount', 14, 2)->default(0);
            $table->unsignedBigInteger('previous_activity_id')->nullable();
            $table->unsignedBigInteger('next_activity_id')->nullable();
            $table->foreign(['agency_id', 'owner_contact_id'])->references(['agency_id', 'id'])->on('contacts')->restrictOnDelete();
            $table->foreign(['agency_id', 'previous_activity_id'])->references(['agency_id', 'id'])->on('activities')->restrictOnDelete();
            $table->foreign(['agency_id', 'next_activity_id'])->references(['agency_id', 'id'])->on('activities')->restrictOnDelete();
            $table->index(['agency_id', 'assigned_to_user_id', 'status']);
        });

        // L'esito dell'originale arriva a 300 caratteri.
        DB::statement('ALTER TABLE activities ALTER COLUMN outcome TYPE text');
        DB::statement("ALTER TABLE activities ADD CONSTRAINT activities_priority_check CHECK (priority IN ('Normale', 'Alta', 'Urgente'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE activities DROP CONSTRAINT IF EXISTS activities_priority_check');
        Schema::table('activities', function (Blueprint $table) {
            $table->dropForeign(['agency_id', 'owner_contact_id']);
            $table->dropForeign(['agency_id', 'previous_activity_id']);
            $table->dropForeign(['agency_id', 'next_activity_id']);
            $table->dropForeign(['outcome_confirmed_by_user_id']);
            $table->dropIndex(['agency_id', 'assigned_to_user_id', 'status']);
            $table->dropColumn(['owner_contact_id', 'outcome_confirmed_by_user_id', 'origin_role', 'internal', 'answered',
                'contact_operation', 'crm_operation', 'crm_category', 'interest', 'event_type', 'amount',
                'previous_activity_id', 'next_activity_id']);
        });
    }
};
