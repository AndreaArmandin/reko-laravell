<?php

use App\Support\Postgis;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Private Trova data of a user (reko_favorites, reko_search_history).
     * No agency_id: a private user belongs to no agency. A favorite is a bookmark,
     * never an access grant; history entries expire and are never wallet transactions.
     */
    public function up(): void
    {
        Postgis::assertPgsql();

        Schema::create('trova_favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('result_key', 240);
            $table->jsonb('snapshot');
            $table->timestamps();

            $table->unique(['user_id', 'result_key'], 'trova_favorites_owner_result_unique');
            $table->index(['user_id', 'updated_at'], 'trova_favorites_owner_time_index');
        });

        Schema::create('trova_search_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('search_id', 80);
            $table->string('kind');
            $table->jsonb('criteria');
            $table->unsignedBigInteger('total');
            $table->timestamp('completed_at');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['user_id', 'search_id'], 'trova_search_history_owner_search_unique');
            $table->index(['user_id', 'completed_at'], 'trova_search_history_owner_time_index');
            $table->index('expires_at');
        });

        Postgis::check('trova_search_history', 'trova_search_history_kind_check', "kind IN ('catalog', 'territorial')");
        Postgis::check('trova_search_history', 'trova_search_history_total_check', 'total >= 0');
        Postgis::check('trova_search_history', 'trova_search_history_expiry_check', 'expires_at > completed_at');
    }

    public function down(): void
    {
        Schema::dropIfExists('trova_search_history');
        Schema::dropIfExists('trova_favorites');
    }
};
