<?php

use App\Support\Postgis;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Replaces the gestionale receipts (censusReceipts, activityCallReceipts, requestToken):
     * a client-generated key per user and command. The same key with the same payload replays
     * the stored response; the same key with a different payload is a conflict.
     */
    public function up(): void
    {
        Postgis::assertPgsql();

        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('scope', 80);
            $table->string('key', 100);
            $table->char('fingerprint', 64);
            $table->jsonb('response')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['agency_id', 'user_id', 'scope', 'key'], 'idempotency_keys_unique');
            $table->index('created_at');
        });

        Postgis::check('idempotency_keys', 'idempotency_keys_key_check', 'length(key) >= 8');
        Postgis::check('idempotency_keys', 'idempotency_keys_fingerprint_check', "fingerprint ~ '^[0-9a-f]{64}$'");
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
