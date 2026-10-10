<?php

use App\Support\Postgis;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Postgis::assertPgsql();

        Schema::create('trova_saved_searches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->jsonb('criteria');
            $table->unsignedBigInteger('total');
            $table->timestamp('completed_at');
            $table->timestamps();

            $table->index(['user_id', 'completed_at'], 'trova_saved_searches_owner_time_index');
        });

        Postgis::check('trova_saved_searches', 'trova_saved_searches_name_check', "length(btrim(name)) BETWEEN 1 AND 160");
        Postgis::check('trova_saved_searches', 'trova_saved_searches_criteria_check', "jsonb_typeof(criteria) = 'object'");
        Postgis::check('trova_saved_searches', 'trova_saved_searches_total_check', 'total >= 0');
    }

    public function down(): void
    {
        Schema::dropIfExists('trova_saved_searches');
    }
};
