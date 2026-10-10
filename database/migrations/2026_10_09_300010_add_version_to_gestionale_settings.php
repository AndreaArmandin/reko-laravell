<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Versione delle impostazioni (settings.version): ogni salvataggio la incrementa e finisce nel punteggio degli abbinamenti. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('gestionale_settings', 'version')) {
            Schema::table('gestionale_settings', function (Blueprint $table) {
                $table->unsignedInteger('version')->default(1);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('gestionale_settings', 'version')) {
            Schema::table('gestionale_settings', function (Blueprint $table) {
                $table->dropColumn('version');
            });
        }
    }
};
