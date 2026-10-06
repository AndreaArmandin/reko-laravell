<?php

use App\Support\Postgis;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Client profiling question sets (gestionale settings.questions), rewriting the deferred draft.
     * agency_id NULL = platform set. The current set of an agency is its highest published version,
     * else the platform one. questions keeps the exact TS Question schema.
     * Seeds the platform set v1 with the 74 default questions (questions.ts, PROFILE_VERSION 5);
     * the Milan prototype zones are not included: zones are configured per agency.
     */
    public function up(): void
    {
        Postgis::assertPgsql();

        Schema::create('question_set_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->nullable();
            $table->string('code', 40);
            $table->unsignedInteger('version');
            $table->unsignedSmallInteger('profile_version');
            $table->jsonb('questions');
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('agency_id')->references('id')->on('agencies')->restrictOnDelete();
        });

        Postgis::uniqueIndex('question_set_versions_platform_unique', 'question_set_versions', 'code, version', 'agency_id IS NULL');
        Postgis::uniqueIndex('question_set_versions_agency_unique', 'question_set_versions', 'agency_id, code, version', 'agency_id IS NOT NULL');
        Postgis::check('question_set_versions', 'question_set_versions_questions_check', "jsonb_typeof(questions) = 'array'");
        Postgis::check('question_set_versions', 'question_set_versions_version_check', 'version >= 1 AND profile_version >= 1');

        $defaults = json_decode((string) file_get_contents(resource_path('gestionale/questionario-v5.json')), true, 512, JSON_THROW_ON_ERROR);
        DB::table('question_set_versions')->insert([
            'agency_id' => null,
            'code' => 'client_profile',
            'version' => 1,
            'profile_version' => $defaults['profileVersion'],
            'questions' => json_encode($defaults['questions'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('question_set_versions');
    }
};
