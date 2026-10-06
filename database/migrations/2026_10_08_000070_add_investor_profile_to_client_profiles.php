<?php

use App\Support\Postgis;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Investor profile of a client, as in the gestionale (investors.ts InvestorProfile:
     * active, min, max, zones, groups, opportunity). Same rules: budgets >= 0, min <= max,
     * groups among the cadastral groups of cadastral-search.ts.
     */
    public function up(): void
    {
        Postgis::assertPgsql();

        Schema::table('client_profiles', function (Blueprint $table) {
            $table->boolean('is_investor')->default(false);
            $table->decimal('investor_budget_min', 14, 2)->nullable();
            $table->decimal('investor_budget_max', 14, 2)->nullable();
            $table->string('investor_zones', 500)->nullable();
            $table->jsonb('investor_groups')->default(DB::raw("'[]'::jsonb"));
            $table->text('investor_opportunity')->nullable();
        });

        Postgis::check('client_profiles', 'client_profiles_investor_budget_check',
            '(investor_budget_min IS NULL OR investor_budget_min >= 0) AND (investor_budget_max IS NULL OR investor_budget_max >= 0) '
            .'AND (investor_budget_min IS NULL OR investor_budget_max IS NULL OR investor_budget_min <= investor_budget_max)');
        Postgis::check('client_profiles', 'client_profiles_investor_groups_check',
            "jsonb_typeof(investor_groups) = 'array' AND investor_groups <@ '[\"A\", \"A10\", \"D\", \"C1\", \"C2\", \"C3\", \"C4\", \"C6\", \"C7\", \"B\", \"E\", \"F\"]'::jsonb");
    }

    public function down(): void
    {
        Schema::table('client_profiles', function (Blueprint $table) {
            $table->dropColumn(['is_investor', 'investor_budget_min', 'investor_budget_max', 'investor_zones', 'investor_groups', 'investor_opportunity']);
        });
    }
};
