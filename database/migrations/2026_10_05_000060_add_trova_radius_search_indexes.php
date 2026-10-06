<?php

use App\Support\Postgis;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for Trova radius search (ST_DWithin on geography) and the common
 * catalogue filters (release + status + search_group, units by parcel).
 */
return new class extends Migration
{
    public function up(): void
    {
        Postgis::assertPgsql();

        // Geometry GiST does not help ST_DWithin(...::geography, ...). Prefer geography.
        DB::statement('DROP INDEX IF EXISTS parcel_search_points_location_gist');
        Postgis::geographyGist('parcel_search_points', 'location');

        Schema::table('cadastral_unit_versions', function (Blueprint $table) {
            $table->index(
                ['catalog_release_id', 'status', 'search_group'],
                'cadastral_unit_versions_release_status_group_index',
            );
        });

        // Unique (parcel_id, identity_key) already covers parcel_id lookups; keep an
        // explicit single-column index for join plans that only need parcel_id.
        Schema::table('cadastral_units', function (Blueprint $table) {
            $table->index('parcel_id', 'cadastral_units_parcel_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('cadastral_units', function (Blueprint $table) {
            $table->dropIndex('cadastral_units_parcel_id_index');
        });

        Schema::table('cadastral_unit_versions', function (Blueprint $table) {
            $table->dropIndex('cadastral_unit_versions_release_status_group_index');
        });

        DB::statement('DROP INDEX IF EXISTS parcel_search_points_location_geog_gist');
        Postgis::gist('parcel_search_points', 'location');
    }
};
