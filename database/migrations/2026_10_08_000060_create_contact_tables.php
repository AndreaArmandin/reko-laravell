<?php

use App\Support\Postgis;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One identity table for gestionale clients and owners (rewrites the contacts part of
     * migrations_deferred/2026_10_02_000060).
     * - contacts: the person or company. Owner-side fields (tax code, birth details, tags) live
     *   here because a CF identifies a person, not a role. Never physically deleted:
     *   removed_* is the TS logical delete (census.owner.delete).
     * - contact_channels: phones and emails, with the TS phone status and a normalized key
     *   for duplicate warnings (indexed, not unique: the gestionale warns, it does not block).
     * - client_profiles: the client role (TS Client): agent, status, channel, consents,
     *   archive/remove lifecycle. A contact without a profile is an owner only.
     * Tenant safety: every child carries agency_id and references its parent with a composite
     * FK (agency_id, id), so a row of agency A can never point at a contact of agency B.
     * The client's agent must be a membership of the same agency (FK to agency_memberships).
     */
    public function up(): void
    {
        Postgis::assertPgsql();

        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->restrictOnDelete();
            $table->string('display_name');
            $table->string('name_key');
            $table->string('given_name')->nullable();
            $table->string('family_name')->nullable();
            $table->string('company_name')->nullable();
            $table->string('tax_code', 32)->nullable();
            $table->string('vat_number', 32)->nullable();
            $table->string('birth_details')->nullable();
            $table->text('notes')->nullable();
            $table->jsonb('tags')->default(DB::raw("'[]'::jsonb"));
            $table->string('origin', 20)->default('manual');
            $table->timestamp('removed_at')->nullable();
            $table->foreignId('removed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('removed_reason')->nullable();
            $table->timestamps();

            $table->unique(['agency_id', 'id'], 'contacts_agency_id_id_unique');
            $table->index(['agency_id', 'name_key']);
        });

        // One identity per tax code inside an agency (TS owner.save); other agencies are separate worlds.
        Postgis::uniqueIndex('contacts_agency_tax_code_unique', 'contacts', 'agency_id, tax_code', 'tax_code IS NOT NULL');
        Postgis::check('contacts', 'contacts_display_name_check', "btrim(display_name) <> ''");
        // Stored already normalized (uppercase, no spaces), as TS normalizedCF + owner.save validation.
        Postgis::check('contacts', 'contacts_tax_code_check', "tax_code IS NULL OR tax_code ~ '^[A-Z0-9-]{3,32}$'");
        Postgis::check('contacts', 'contacts_tags_check', "jsonb_typeof(tags) = 'array'");
        Postgis::check('contacts', 'contacts_origin_check', "origin IN ('manual', 'sister', 'legacy_import')");
        Postgis::check('contacts', 'contacts_removed_check', "removed_at IS NULL OR btrim(coalesce(removed_reason, '')) <> ''");

        Schema::create('contact_channels', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('contact_id');
            $table->string('kind', 10);
            $table->string('value', 160);
            $table->string('normalized_value', 160);
            $table->string('label', 60)->nullable();
            $table->string('status', 20)->default('Da verificare');
            $table->timestamp('verified_at')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->foreign('agency_id')->references('id')->on('agencies')->restrictOnDelete();
            $table->foreign(['agency_id', 'contact_id'], 'contact_channels_contact_fk')
                ->references(['agency_id', 'id'])->on('contacts')->restrictOnDelete();
            $table->unique(['contact_id', 'kind', 'normalized_value'], 'contact_channels_contact_value_unique');
            $table->index(['agency_id', 'kind', 'normalized_value'], 'contact_channels_duplicate_lookup');
        });

        Postgis::uniqueIndex('contact_channels_one_primary', 'contact_channels', 'contact_id, kind', 'is_primary');
        Postgis::check('contact_channels', 'contact_channels_kind_check', "kind IN ('phone', 'email')");
        Postgis::check('contact_channels', 'contact_channels_value_check', "btrim(value) <> '' AND normalized_value <> ''");
        Postgis::check('contact_channels', 'contact_channels_status_check', "status IN ('Da verificare', 'Verificato', 'Errato')");

        Schema::create('client_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('contact_id')->unique();
            $table->unsignedBigInteger('agent_user_id');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 30)->default('Nuovo');
            $table->string('preferred_channel', 20)->nullable();
            $table->string('contact_time', 60)->nullable();
            $table->string('source', 120)->nullable();
            $table->text('next_action')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('consent_practice')->default(false);
            $table->boolean('consent_marketing')->default(false);
            $table->timestamp('consents_updated_at')->nullable();
            $table->string('lifecycle_state', 10)->nullable();
            $table->timestamp('lifecycle_at')->nullable();
            $table->foreignId('lifecycle_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('lifecycle_reason')->nullable();
            $table->timestamps();

            $table->foreign('agency_id')->references('id')->on('agencies')->restrictOnDelete();
            $table->foreign(['agency_id', 'contact_id'], 'client_profiles_contact_fk')
                ->references(['agency_id', 'id'])->on('contacts')->restrictOnDelete();
            // The referent must belong to the same agency; an operator with clients is deactivated, not removed.
            $table->foreign(['agency_id', 'agent_user_id'], 'client_profiles_agent_membership_fk')
                ->references(['agency_id', 'user_id'])->on('agency_memberships')->restrictOnDelete();
            $table->index(['agency_id', 'agent_user_id']);
            $table->index(['agency_id', 'status']);
        });

        Postgis::check('client_profiles', 'client_profiles_status_check',
            "status IN ('Nuovo', 'Da contattare', 'Da profilare', 'Profilato', 'Attivo', 'In trattativa', 'Concluso', 'Sospeso', 'Non interessato')");
        Postgis::check('client_profiles', 'client_profiles_channel_check',
            "preferred_channel IS NULL OR preferred_channel IN ('Telefono', 'Email', 'Messaggio', 'In presenza')");
        Postgis::check('client_profiles', 'client_profiles_lifecycle_check',
            "(lifecycle_state IS NULL AND lifecycle_at IS NULL) OR (lifecycle_state IN ('archived', 'removed') AND lifecycle_at IS NOT NULL)");
    }

    public function down(): void
    {
        Schema::dropIfExists('client_profiles');
        Schema::dropIfExists('contact_channels');
        Schema::dropIfExists('contacts');
    }
};
