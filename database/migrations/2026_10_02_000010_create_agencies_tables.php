<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Agencies and memberships sit beside the starter-kit users table.
     */
    public function up(): void
    {
        Schema::create('agencies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('vat_number')->nullable();
            $table->timestamps();
        });

        Schema::create('agency_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->timestamps();

            $table->unique(['agency_id', 'user_id']);
        });

        DB::statement("ALTER TABLE agency_memberships ADD CONSTRAINT agency_memberships_role_check CHECK (role IN ('admin', 'scout', 'crm'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agency_memberships');
        Schema::dropIfExists('agencies');
    }
};
