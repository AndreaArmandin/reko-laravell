<?php

namespace Database\Seeders;

use App\Models\AgencyMembership;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Adds a small, repeatable set of clearly fake census records to the local demo agency.
 * Run with REKO_DEMO_AGENCY_ID=<agency id> php artisan db:seed --class=CensusArchiveDemoSeeder --force
 */
class CensusArchiveDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException('I dati dimostrativi dell’Archivio si possono inserire solo in ambiente local o testing.');
        }

        $agencyId = (int) env('REKO_DEMO_AGENCY_ID', 0);
        if ($agencyId <= 0) {
            throw new RuntimeException('Imposta REKO_DEMO_AGENCY_ID con l’agenzia locale da usare.');
        }

        $admin = AgencyMembership::query()->where('agency_id', $agencyId)->where('role', 'admin')->active()->orderBy('user_id')->first();
        if ($admin === null) {
            throw new RuntimeException('Non trovo un amministratore attivo per l’agenzia selezionata.');
        }

        $municipality = DB::table('municipalities')->where('cadastral_code', 'X001')->where('name', 'Comune Demo')->first();
        if ($municipality === null) {
            throw new RuntimeException('Il Comune Demo X001 non è presente nel database.');
        }

        DB::transaction(function () use ($agencyId, $admin, $municipality): void {
            $now = now();
            $ownerIds = [];

            for ($ownerNumber = 1; $ownerNumber <= 12; $ownerNumber++) {
                $serial = str_pad((string) $ownerNumber, 4, '0', STR_PAD_LEFT);
                $taxCode = 'DEMOARC'.$serial;
                $name = 'Proprietario Demo '.$serial;
                DB::table('contacts')->updateOrInsert(
                    ['agency_id' => $agencyId, 'tax_code' => $taxCode],
                    [
                        'display_name' => $name,
                        'name_key' => mb_strtolower($name),
                        'given_name' => 'Proprietario',
                        'family_name' => 'Demo '.$serial,
                        'company_name' => null,
                        'birth_details' => null,
                        'notes' => 'Dato fittizio: non usare per contatti reali.',
                        'tags' => json_encode(['DATI FITTIZI']),
                        'origin' => 'manual',
                        'recapito' => 'Recapito fittizio · non contattare',
                        'contact_history' => json_encode([]),
                        'imported_by_user_id' => $admin->user_id,
                        'updated_at' => $now,
                        'created_at' => $now,
                    ],
                );
                $ownerIds[$ownerNumber] = (int) DB::table('contacts')->where('agency_id', $agencyId)->where('tax_code', $taxCode)->value('id');
            }

            for ($number = 1; $number <= 30; $number++) {
                $parcelIndex = intdiv($number - 1, 3);
                $sheet = (string) (9901 + intdiv($parcelIndex, 5));
                $parcelNumber = (string) (920001 + $parcelIndex);
                $sub = (string) ((($number - 1) % 3) + 1);

                DB::table('parcels')->updateOrInsert(
                    ['municipality_id' => $municipality->id, 'cadastral_kind' => 'F', 'section' => '', 'sheet' => $sheet, 'number' => $parcelNumber],
                    ['updated_at' => $now, 'created_at' => $now],
                );
                $parcelId = (int) DB::table('parcels')->where('municipality_id', $municipality->id)->where('cadastral_kind', 'F')
                    ->where('section', '')->where('sheet', $sheet)->where('number', $parcelNumber)->value('id');

                DB::table('cadastral_units')->updateOrInsert(
                    ['parcel_id' => $parcelId, 'subalterno' => $sub],
                    ['source_ref' => null, 'updated_at' => $now, 'created_at' => $now],
                );
                $unitId = (int) DB::table('cadastral_units')->where('parcel_id', $parcelId)->where('subalterno', $sub)->value('id');

                $streetNumber = str_pad((string) $number, 2, '0', STR_PAD_LEFT);
                $street = 'Via Fittizia '.$streetNumber;
                $civic = (string) (2 + (($number - 1) % 18));
                $category = ['A/2', 'A/3', 'A/4', 'C/2', 'A/7'][$number % 5];
                DB::table('agency_unit_observations')->updateOrInsert(
                    ['agency_id' => $agencyId, 'cadastral_unit_id' => $unitId],
                    [
                        'user_id' => $admin->user_id,
                        'observed_on' => $now->toDateString(),
                        'condition_notes' => 'Dato fittizio creato per provare l’Archivio catastale.',
                        'asking_price' => null,
                        'occupancy' => null,
                        'data' => json_encode((object) []),
                        'source' => 'demo',
                        'state' => 'Attivo',
                        'category' => $category,
                        'census_zone' => null,
                        'cadastral_class' => ['1', '2', '3'][$number % 3],
                        'consistency' => (string) (2 + ($number % 6)).' vani',
                        'consistency_value' => (float) (2 + ($number % 6)),
                        'consistency_unit' => 'vani',
                        'income' => 75 + ($number * 17.25),
                        'batch' => 'DATI-FITTIZI-ARCHIVIO',
                        'address' => $street.' n. '.$civic,
                        'raw_address' => $street.' n. '.$civic.' Piano '.($number % 4 === 0 ? 'T' : (string) ($number % 4)),
                        'floor' => $number % 4 === 0 ? 'T' : (string) ($number % 4),
                        'street' => $street,
                        'civic' => $civic,
                        'locality' => ['Borgo di prova', 'Centro demo', 'Frazione fittizia'][$number % 3],
                        'location_source_address' => null,
                        'raw_text' => 'DATI FITTIZI — scheda demo '.$streetNumber.'; non proveniente da SISTER.',
                        'raw_classing' => null,
                        'situation_date' => $now->toDateString(),
                        'identity_detail' => null,
                        'catalog_key' => null,
                        'last_verified' => null,
                        'removed' => null,
                        'subject_holding' => null,
                        'census_batch_id' => null,
                        'updated_at' => $now,
                        'created_at' => $now,
                    ],
                );

                if ($number % 7 === 0) {
                    continue;
                }

                $primary = (($number - 1) % 12) + 1;
                $holdingOwners = [$primary => '1/1'];
                if ($number % 5 === 0) {
                    $holdingOwners[$primary % 12 + 1] = '1/2';
                    $holdingOwners[$primary] = '1/2';
                }

                foreach ($holdingOwners as $ownerNumber => $fraction) {
                    [$numerator, $denominator] = array_map('intval', explode('/', $fraction));
                    DB::table('ownerships')->updateOrInsert(
                        ['agency_id' => $agencyId, 'cadastral_unit_id' => $unitId, 'contact_id' => $ownerIds[$ownerNumber], 'valid_to' => null],
                        [
                            'parcel_id' => $parcelId,
                            'share_numerator' => $numerator,
                            'share_denominator' => $denominator,
                            'right_type' => 'Proprietà',
                            'valid_from' => $now->toDateString(),
                            'source' => 'demo',
                            'details' => json_encode(['holding' => ['right' => 'Proprietà', 'fraction' => $fraction, 'rawText' => 'DATI FITTIZI'], 'source' => 'demo', 'reason' => 'Dati fittizi di prova']),
                            'updated_at' => $now,
                            'created_at' => $now,
                        ],
                    );
                }
            }
        });

        $this->command?->info('Archivio demo creato/aggiornato: 30 unità fittizie e 12 intestatari nel Comune Demo (X001).');
    }
}
