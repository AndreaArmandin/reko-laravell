<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| Zone di scouting come nell'originale (types.ts ScoutingZone): piano di censimento (plan) accanto ai confini,
| revisione per record con i microsecondi, una sola assegnazione per particella (parcelAgents) e una sola
| combinazione di filtri con lo stesso nome per operatore (census.filter.save).
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE scouting_zones ADD COLUMN IF NOT EXISTS plan jsonb NOT NULL DEFAULT '[]'::jsonb");
        DB::statement("ALTER TABLE scouting_zones ADD CONSTRAINT scouting_zones_plan_check CHECK (jsonb_typeof(plan) = 'array')");
        DB::statement('ALTER TABLE scouting_zones ALTER COLUMN created_at TYPE timestamp(6) without time zone, ALTER COLUMN updated_at TYPE timestamp(6) without time zone');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS scouting_assignments_parcel_unique ON scouting_assignments (agency_id, parcel_id) WHERE parcel_id IS NOT NULL AND cadastral_unit_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS census_saved_filters_name_unique ON census_saved_filters (agency_id, user_id, name)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS census_saved_filters_name_unique');
        DB::statement('DROP INDEX IF EXISTS scouting_assignments_parcel_unique');
        DB::statement('ALTER TABLE scouting_zones DROP CONSTRAINT IF EXISTS scouting_zones_plan_check');
        DB::statement('ALTER TABLE scouting_zones DROP COLUMN IF EXISTS plan');
    }
};
