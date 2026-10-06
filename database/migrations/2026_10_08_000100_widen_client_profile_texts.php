<?php

use App\Support\Postgis;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The gestionale accepts contact time and source up to 4000 characters (engine.ts text()):
     * widen the Phase 1 columns instead of rejecting valid old data. updated_at gets microsecond
     * precision because it is the optimistic revision of client.save (expected_updated_at).
     */
    public function up(): void
    {
        Postgis::assertPgsql();

        DB::statement('ALTER TABLE client_profiles ALTER COLUMN contact_time TYPE text, ALTER COLUMN source TYPE text, ALTER COLUMN updated_at TYPE timestamp(6) without time zone');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE client_profiles ALTER COLUMN contact_time TYPE varchar(60) USING left(contact_time, 60), ALTER COLUMN source TYPE varchar(120) USING left(source, 120), ALTER COLUMN updated_at TYPE timestamp(0) without time zone');
    }
};
