<?php

use App\Gestionale\Census\ArchiveColumns;
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

        Schema::table('agency_unit_observations', function (Blueprint $table) {
            $table->foreignId('municipality_id')->nullable()->constrained('municipalities')->nullOnDelete();
            $table->string('last_outcome')->nullable();
            $table->timestamp('last_outcome_at')->nullable();
            $table->text('address_sort')->nullable();
            $table->text('archive_sort')->nullable();
            $table->text('search_text')->nullable();
            $table->text('filter_address')->nullable();
            $table->text('filter_civic')->nullable();
        });

        ArchiveColumns::backfill();

        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement("CREATE INDEX agency_unit_observations_archive_asc ON agency_unit_observations (agency_id, municipality_id, last_outcome_at ASC NULLS FIRST, archive_sort) WHERE state = 'Attivo' AND removed IS NULL");
        DB::statement("CREATE INDEX agency_unit_observations_archive_desc ON agency_unit_observations (agency_id, municipality_id, last_outcome_at DESC NULLS LAST, archive_sort) WHERE state = 'Attivo' AND removed IS NULL");
        DB::statement("CREATE INDEX agency_unit_observations_archive_outcome ON agency_unit_observations (agency_id, municipality_id, last_outcome, last_outcome_at ASC NULLS FIRST, archive_sort) WHERE state = 'Attivo' AND removed IS NULL");
        DB::statement('CREATE INDEX agency_unit_observations_search_trgm ON agency_unit_observations USING GIN (search_text gin_trgm_ops)');
        DB::statement('CREATE INDEX agency_unit_observations_address_trgm ON agency_unit_observations USING GIN (filter_address gin_trgm_ops)');
        DB::statement('CREATE INDEX agency_unit_observations_locality_trgm ON agency_unit_observations USING GIN (locality gin_trgm_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS agency_unit_observations_archive_asc');
        DB::statement('DROP INDEX IF EXISTS agency_unit_observations_archive_desc');
        DB::statement('DROP INDEX IF EXISTS agency_unit_observations_archive_outcome');
        DB::statement('DROP INDEX IF EXISTS agency_unit_observations_search_trgm');
        DB::statement('DROP INDEX IF EXISTS agency_unit_observations_address_trgm');
        DB::statement('DROP INDEX IF EXISTS agency_unit_observations_locality_trgm');

        Schema::table('agency_unit_observations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('municipality_id');
            $table->dropColumn(['last_outcome', 'last_outcome_at', 'address_sort', 'archive_sort', 'search_text', 'filter_address', 'filter_civic']);
        });
    }
};
