<?php

use App\Support\Postgis;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Motore degli obiettivi (lib/crm/goals.ts) e dashboard personalizzabile (dashboard-layout.ts).
| - goal_events: anche le pietre miliari che non nascono da un'attività (richiesta completata, immobile
|   pubblicato, trattativa avviata...), quindi activity_id può mancare e ogni evento ha una chiave stabile
|   ("activity:12", "request:5:complete") che è anche quella usata per non registrarlo due volte.
| - goal_ledger: ogni riga ha una chiave stabile (goal:inizio:evento) e conserva la chiave dell'evento senza
|   vincolo, perché l'originale ricostruisce gli eventi dalle attività e tiene intatti i periodi chiusi.
| - dashboard_layouts: disposizione dei riquadri di "Oggi", per utente e profilo.
*/
return new class extends Migration
{
    public function up(): void
    {
        Postgis::assertPgsql();

        // Gli obiettivi iniziali sono modificabili dal responsabile come tutti gli altri.
        DB::table('goals')->where('read_only', true)->update(['read_only' => false]);

        Schema::table('goal_events', function (Blueprint $table) {
            $table->unsignedBigInteger('activity_id')->nullable()->change();
            $table->string('event_key', 120)->nullable()->after('agency_id');
        });
        DB::statement("UPDATE goal_events SET event_key = 'activity:' || activity_id WHERE event_key IS NULL");
        DB::statement('ALTER TABLE goal_events ALTER COLUMN event_key SET NOT NULL');
        Schema::table('goal_events', function (Blueprint $table) {
            $table->unique(['agency_id', 'event_key'], 'goal_events_agency_key_unique');
        });

        Schema::table('goal_ledger', function (Blueprint $table) {
            $table->dropForeign(['agency_id', 'goal_event_id']);
        });
        Schema::table('goal_ledger', function (Blueprint $table) {
            $table->unsignedBigInteger('goal_event_id')->nullable()->change();
            $table->string('event_key', 120)->nullable()->after('goal_event_id');
            $table->string('ledger_key')->nullable()->after('event_key');
            $table->unique(['agency_id', 'ledger_key'], 'goal_ledger_agency_key_unique');
            $table->index(['agency_id', 'period_end'], 'goal_ledger_agency_end_index');
        });

        Schema::create('dashboard_layouts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('user_id');
            $table->string('role', 10);
            $table->jsonb('layout')->default(DB::raw("'[]'::jsonb"));
            $table->timestamps();
            $table->foreign(['agency_id', 'user_id'])->references(['agency_id', 'user_id'])->on('agency_memberships')->cascadeOnDelete();
            $table->unique(['agency_id', 'user_id', 'role']);
        });
        Postgis::check('dashboard_layouts', 'dashboard_layouts_layout_check', "jsonb_typeof(layout) = 'array'");
        Postgis::check('dashboard_layouts', 'dashboard_layouts_role_check', "role IN ('admin', 'crm', 'scout')");

        // "Inizia da qui" chiuso (l'originale lo ricorda nel browser, qui per utente).
        Schema::table('agency_memberships', function (Blueprint $table) {
            $table->timestamp('first_steps_closed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('agency_memberships', function (Blueprint $table) {
            $table->dropColumn('first_steps_closed_at');
        });
        Schema::dropIfExists('dashboard_layouts');
        Schema::table('goal_ledger', function (Blueprint $table) {
            $table->dropIndex('goal_ledger_agency_end_index');
            $table->dropUnique('goal_ledger_agency_key_unique');
            $table->dropColumn(['event_key', 'ledger_key']);
        });
        Schema::table('goal_events', function (Blueprint $table) {
            $table->dropUnique('goal_events_agency_key_unique');
            $table->dropColumn('event_key');
        });
    }
};
