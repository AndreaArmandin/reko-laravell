<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_runs', function (Blueprint $table) {
            // Riepilogo dell'import: conteggi, confronto con l'edizione precedente, mappatura usata
            $table->jsonb('summary')->nullable()->after('rows_rejected');
        });
    }

    public function down(): void
    {
        Schema::table('import_runs', function (Blueprint $table) {
            $table->dropColumn('summary');
        });
    }
};
