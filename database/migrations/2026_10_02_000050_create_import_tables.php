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

        Schema::create('import_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('catalog_release_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_name');
            $table->string('original_filename');
            $table->string('private_path')->nullable();
            $table->char('sha256', 64);
            $table->string('parser_version');
            $table->string('status');
            $table->unsignedBigInteger('rows_read')->default(0);
            $table->unsignedBigInteger('rows_imported')->default(0);
            $table->unsignedBigInteger('rows_rejected')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('import_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_run_id')->constrained()->cascadeOnDelete();
            $table->string('severity');
            $table->string('code');
            $table->text('message');
            $table->unsignedBigInteger('row_number')->nullable();
            $table->jsonb('payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_issues');
        Schema::dropIfExists('import_runs');
    }
};
