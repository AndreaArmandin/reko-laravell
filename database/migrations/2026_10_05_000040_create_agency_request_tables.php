<?php

use App\Support\Postgis;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Requests a Trova user sends to an agency, and the agency's private attachments
     * (reko_agency_requests, reko_agency_attachments). The agency is a real FK, not a text label.
     */
    public function up(): void
    {
        Postgis::assertPgsql();

        Schema::create('agency_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requester_user_id')->constrained('users')->cascadeOnDelete();
            // Client-generated id: a retried submission cannot create a second request.
            $table->string('client_request_id', 80);
            $table->jsonb('reference');
            $table->text('message');
            $table->string('status')->default('nuova');
            $table->timestamps();

            $table->unique(['requester_user_id', 'client_request_id'], 'agency_requests_requester_client_unique');
            $table->index(['agency_id', 'created_at'], 'agency_requests_inbox_index');
        });

        Postgis::check('agency_requests', 'agency_requests_status_check', "status IN ('nuova', 'in lavorazione', 'completata')");
        Postgis::check('agency_requests', 'agency_requests_message_check', 'length(message) <= 2000');

        Schema::create('agency_request_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_request_id')->constrained()->cascadeOnDelete();
            // Client-generated upload id: a retried upload reconciles instead of duplicating.
            $table->string('upload_id', 80);
            $table->string('private_path');
            $table->char('sha256', 64);
            $table->string('original_name', 150);
            $table->string('mime_type');
            $table->unsignedBigInteger('byte_size');
            $table->foreignId('cadastral_unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['agency_request_id', 'upload_id'], 'agency_request_attachments_upload_unique');
        });

        Postgis::check('agency_request_attachments', 'agency_request_attachments_mime_check',
            "mime_type IN ('application/pdf', 'image/png', 'image/jpeg')");
        Postgis::check('agency_request_attachments', 'agency_request_attachments_size_check',
            'byte_size > 0 AND byte_size <= 10000000');
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_request_attachments');
        Schema::dropIfExists('agency_requests');
    }
};
