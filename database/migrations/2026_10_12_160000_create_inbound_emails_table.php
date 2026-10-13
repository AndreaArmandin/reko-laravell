<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Personal mailbox link: only the SHA-256 of the key is stored.
        Schema::table('users', function (Blueprint $table) {
            $table->string('inbound_token_hash', 64)->nullable()->unique();
            $table->text('inbound_allowed_senders')->nullable();
        });

        // Portal notification emails pushed by the user's mailbox script. The raw payload is always kept.
        Schema::create('inbound_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('message_id', 300);
            $table->string('source', 40)->default('email');
            $table->string('sender', 320);
            $table->string('subject', 500)->default('');
            $table->text('body');
            $table->jsonb('raw');
            $table->string('listing_url', 300)->default('');
            $table->string('phone', 40)->default('');
            $table->timestamp('received_at', 6);
            $table->string('status', 20)->default('nuova');
            $table->timestamps(6);
            $table->unique(['user_id', 'message_id']);
            $table->index(['user_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_emails');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['inbound_token_hash', 'inbound_allowed_senders']);
        });
    }
};
