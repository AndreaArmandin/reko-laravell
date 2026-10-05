<?php

use App\Support\Postgis;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * PostGIS cannot always be created inside a transaction.
     *
     * @var bool
     */
    public $withinTransaction = false;

    /**
     * Enable PostGIS before any geometry column is added.
     */
    public function up(): void
    {
        Postgis::enable();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Postgis::assertPgsql();

        DB::statement('DROP EXTENSION IF EXISTS postgis');
    }
};
