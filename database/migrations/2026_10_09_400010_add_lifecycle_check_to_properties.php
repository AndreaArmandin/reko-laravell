<?php

use App\Support\Postgis;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Archiviazione e rimozione reversibile di un immobile (record-lifecycle.ts), come per clienti e richieste:
     * lo stato è archived o removed e ha sempre una data; senza stato non ci sono dati di conservazione.
     */
    public function up(): void
    {
        Postgis::assertPgsql();

        DB::statement("UPDATE properties SET lifecycle_state = NULL WHERE lifecycle_state IS NOT NULL AND lifecycle_state NOT IN ('archived', 'removed')");
        DB::statement('UPDATE properties SET lifecycle_at = COALESCE(updated_at, now()) WHERE lifecycle_state IS NOT NULL AND lifecycle_at IS NULL');
        DB::statement('UPDATE properties SET lifecycle_at = NULL, lifecycle_by_user_id = NULL, lifecycle_reason = NULL WHERE lifecycle_state IS NULL');
        Postgis::check('properties', 'properties_lifecycle_check',
            "(lifecycle_state IS NULL AND lifecycle_at IS NULL) OR (lifecycle_state IN ('archived', 'removed') AND lifecycle_at IS NOT NULL)");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE properties DROP CONSTRAINT IF EXISTS properties_lifecycle_check');
    }
};
