<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Personal, unguessable tag of the user's forwarding address (<base>+<code>@…).
        Schema::table('users', function (Blueprint $table) {
            $table->string('inbound_code', 32)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('inbound_code');
        });
    }
};
