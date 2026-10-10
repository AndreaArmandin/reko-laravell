<?php

use App\Support\Postgis;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Archivio catastale dell'agenzia (censimento), porting di census-model.ts / census-sister.ts.
     * - agency_unit_observations diventa la scheda di censimento dell'agenzia per una unità catastale:
     *   dati della fonte Sister (testo originale incluso), stato (Attivo, Soppresso, Eliminato), ubicazione
     *   corretta a mano (via, civico, frazione). Le tabelle parcels e cadastral_units restano l'identità comune.
     * - contacts: recapito libero e cronologia delle sue variazioni (DemoOwner.recapito, contactHistory),
     *   operatore che ha importato l'anagrafica (DemoOwner.importedBy).
     * - ownerships: una sola intestazione attuale (valid_to nullo) per proprietario e unità; le chiuse restano
     *   nello storico (holdingHistory).
     * - properties.cadastral_warning: avviso di verifica catastale sugli immobili a portafoglio collegati.
     * - agency_memberships.catalog_package: pacchetto di acquisizione dal catalogo (User.catalogPackage).
     */
    public function up(): void
    {
        Postgis::assertPgsql();

        Schema::table('agency_unit_observations', function (Blueprint $table) {
            $table->string('source', 20)->default('sister-text');
            $table->string('state', 12)->default('Attivo');
            $table->string('category', 20)->nullable();
            $table->string('census_zone', 40)->nullable();
            $table->string('cadastral_class', 40)->nullable();
            $table->string('consistency', 60)->nullable();
            $table->decimal('consistency_value', 12, 2)->nullable();
            $table->string('consistency_unit', 20)->nullable();
            $table->decimal('income', 14, 2)->nullable();
            $table->string('batch', 60)->nullable();
            $table->text('address')->nullable();
            $table->text('raw_address')->nullable();
            $table->string('floor', 100)->nullable();
            $table->string('street', 255)->nullable();
            $table->string('civic', 30)->nullable();
            $table->string('locality', 120)->nullable();
            $table->text('location_source_address')->nullable();
            $table->text('raw_text')->nullable();
            $table->string('raw_classing', 60)->nullable();
            $table->date('situation_date')->nullable();
            $table->text('identity_detail')->nullable();
            $table->text('catalog_key')->nullable();
            $table->date('last_verified')->nullable();
            $table->jsonb('removed')->nullable();
            $table->jsonb('subject_holding')->nullable();
            $table->unsignedBigInteger('census_batch_id')->nullable();
        });
        Postgis::uniqueIndex('agency_unit_observations_agency_unit_unique', 'agency_unit_observations', 'agency_id, cadastral_unit_id');
        Postgis::check('agency_unit_observations', 'agency_unit_observations_state_check', "state IN ('Attivo', 'Soppresso', 'Eliminato')");
        Postgis::check('agency_unit_observations', 'agency_unit_observations_source_check', "source IN ('sister-text', 'catalog', 'demo')");

        Schema::table('contacts', function (Blueprint $table) {
            $table->text('recapito')->nullable();
            $table->jsonb('contact_history')->default(DB::raw("'[]'::jsonb"));
            $table->foreignId('imported_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        });
        Postgis::check('contacts', 'contacts_contact_history_check', "jsonb_typeof(contact_history) = 'array'");

        // Un proprietario ha una sola intestazione attuale per unità: lo stesso CF non si duplica nella scheda.
        Postgis::uniqueIndex('ownerships_one_current_per_unit', 'ownerships', 'agency_id, contact_id, cadastral_unit_id', 'valid_to IS NULL AND contact_id IS NOT NULL AND cadastral_unit_id IS NOT NULL');
        Schema::table('ownerships', function (Blueprint $table) {
            $table->index(['agency_id', 'cadastral_unit_id', 'valid_to'], 'ownerships_unit_current_index');
            $table->index(['agency_id', 'contact_id', 'valid_to'], 'ownerships_contact_current_index');
        });

        // Property.cadastralWarning: "Verifica catastale necessaria" quando un'unità collegata viene soppressa o eliminata.
        if (! Schema::hasColumn('properties', 'cadastral_warning')) {
            Schema::table('properties', function (Blueprint $table) {
                $table->string('cadastral_warning')->nullable();
            });
        }

        if (! Schema::hasColumn('agency_memberships', 'catalog_package')) {
            Schema::table('agency_memberships', function (Blueprint $table) {
                $table->jsonb('catalog_package')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('properties', 'cadastral_warning')) {
            Schema::table('properties', fn (Blueprint $table) => $table->dropColumn('cadastral_warning'));
        }

        if (Schema::hasColumn('agency_memberships', 'catalog_package')) {
            Schema::table('agency_memberships', fn (Blueprint $table) => $table->dropColumn('catalog_package'));
        }

        Schema::table('ownerships', function (Blueprint $table) {
            $table->dropIndex('ownerships_unit_current_index');
            $table->dropIndex('ownerships_contact_current_index');
        });
        DB::statement('DROP INDEX IF EXISTS ownerships_one_current_per_unit');

        DB::statement('ALTER TABLE contacts DROP CONSTRAINT IF EXISTS contacts_contact_history_check');
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('imported_by_user_id');
            $table->dropColumn(['recapito', 'contact_history']);
        });

        DB::statement('ALTER TABLE agency_unit_observations DROP CONSTRAINT IF EXISTS agency_unit_observations_state_check');
        DB::statement('ALTER TABLE agency_unit_observations DROP CONSTRAINT IF EXISTS agency_unit_observations_source_check');
        DB::statement('DROP INDEX IF EXISTS agency_unit_observations_agency_unit_unique');
        Schema::table('agency_unit_observations', function (Blueprint $table) {
            $table->dropColumn(['source', 'state', 'category', 'census_zone', 'cadastral_class', 'consistency', 'consistency_value',
                'consistency_unit', 'income', 'batch', 'address', 'raw_address', 'floor', 'street', 'civic', 'locality',
                'location_source_address', 'raw_text', 'raw_classing', 'situation_date', 'identity_detail', 'catalog_key',
                'last_verified', 'removed', 'subject_holding', 'census_batch_id']);
        });
    }
};
