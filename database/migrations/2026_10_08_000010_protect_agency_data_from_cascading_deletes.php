<?php

use App\Support\Postgis;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An agency is never deleted while it owns data: it is deactivated instead.
     * - agencies.deactivated_at: soft switch-off, the agency disappears from the current-agency
     *   resolver but its CRM, requests and audit trail stay intact.
     * - agency_requests.agency_id goes from CASCADE to RESTRICT. The admin page already refused
     *   to delete an agency with requests; now the database enforces the same rule.
     * agency_memberships keeps CASCADE on purpose: an empty agency can still be deleted and its
     * links to users go with it. A membership referenced by CRM rows is protected by those rows' FKs.
     */
    public function up(): void
    {
        Postgis::assertPgsql();

        Schema::table('agencies', function (Blueprint $table) {
            $table->timestamp('deactivated_at')->nullable();
        });

        Schema::table('agency_requests', function (Blueprint $table) {
            $table->dropForeign(['agency_id']);
            $table->foreign('agency_id')->references('id')->on('agencies')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('agency_requests', function (Blueprint $table) {
            $table->dropForeign(['agency_id']);
            $table->foreign('agency_id')->references('id')->on('agencies')->cascadeOnDelete();
        });

        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn('deactivated_at');
        });
    }
};
