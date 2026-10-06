<?php

use App\Support\Postgis;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Gestionale operator fields on the agency membership (TS User: active, permissions).
     * - deactivated_at: the gestionale deactivates operators instead of deleting them, so their
     *   assignments and audit history keep pointing at a real membership. NULL = active.
     * - permissions: optional permissions of permissions.ts. NULL means "TS defaults"
     *   (activities.assign, activities.share, exports); an array is the explicit list.
     * group/branch (goals) and the catalog package (catalog.acquire) are added with the phases
     * that use them.
     */
    public function up(): void
    {
        Postgis::assertPgsql();

        Schema::table('agency_memberships', function (Blueprint $table) {
            $table->timestamp('deactivated_at')->nullable();
            $table->jsonb('permissions')->nullable();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE agency_memberships ADD CONSTRAINT agency_memberships_permissions_check CHECK (
                permissions IS NULL OR (
                    jsonb_typeof(permissions) = 'array'
                    AND permissions <@ '["sister.import", "owner.edit", "activities.assign", "activities.share", "exports"]'::jsonb
                )
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE agency_memberships DROP CONSTRAINT IF EXISTS agency_memberships_permissions_check');

        Schema::table('agency_memberships', function (Blueprint $table) {
            $table->dropColumn(['deactivated_at', 'permissions']);
        });
    }
};
