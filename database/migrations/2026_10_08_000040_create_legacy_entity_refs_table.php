<?php

use App\Support\Postgis;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Maps legacy string ids (gestionale CRMState: c-…, r-…, p-…, act-…, numeric owner ids)
     * to REKO rows. It makes the one-off JSON import idempotent: a re-run finds the row it
     * already created instead of duplicating it.
     * Not unique on the REKO side: two legacy clients merged into one contact keep both keys.
     * payload_sha256 lets a re-import tell "unchanged" from "changed upstream".
     */
    public function up(): void
    {
        Postgis::assertPgsql();

        Schema::create('legacy_entity_refs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->restrictOnDelete();
            $table->string('source_system', 40);
            $table->string('legacy_type', 40);
            $table->string('legacy_id', 120);
            $table->string('entity_type', 60);
            $table->unsignedBigInteger('entity_id');
            $table->char('payload_sha256', 64)->nullable();
            $table->timestamps();

            $table->unique(['agency_id', 'source_system', 'legacy_type', 'legacy_id'], 'legacy_entity_refs_legacy_unique');
            $table->index(['entity_type', 'entity_id']);
        });

        Postgis::check('legacy_entity_refs', 'legacy_entity_refs_legacy_id_check', "btrim(legacy_id) <> ''");
        Postgis::check('legacy_entity_refs', 'legacy_entity_refs_sha_check', "payload_sha256 IS NULL OR payload_sha256 ~ '^[0-9a-f]{64}$'");
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_entity_refs');
    }
};
