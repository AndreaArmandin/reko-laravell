<?php

namespace Database\Seeders;

use App\Gestionale\Actions\Clients\SaveClient;
use App\Gestionale\Actions\Properties\SaveProperty;
use App\Gestionale\Actions\Requests\FinishPropertyRequest;
use App\Gestionale\Actions\Requests\SavePropertyRequest;
use App\Gestionale\Audit;
use App\Gestionale\Commands;
use App\Models\Activity;
use App\Models\Agency;
use App\Models\AgencyMembership;
use App\Models\Contact;
use App\Models\Property;
use App\Models\PropertyRequest;
use App\Models\ScoutingZone;
use App\Models\ScoutingZoneAssignment;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class GestionaleDemoSeeder
{
    /** @return array{clients:int,requests:int,properties:int,activities:int,units:int} */
    public function seed(Agency $agency, User $user): array
    {
        $membership = AgencyMembership::query()->where('agency_id', $agency->id)->where('user_id', $user->id)->first();
        if (! $membership) {
            $membership = AgencyMembership::query()->create(['agency_id' => $agency->id, 'user_id' => $user->id, 'role' => 'admin']);
        }
        if (! $membership->isActive()) {
            throw new \RuntimeException('L’utente selezionato ha un accesso disattivato all’agenzia.');
        }
        if ($membership->role !== 'admin') {
            throw new \RuntimeException('Il seeder non modifica ruoli esistenti. Usa un utente senza membership o assegna il ruolo demo dal pannello Admin.');
        }

        $oldUser = Auth::user();
        Auth::login($user);
        try {
            return DB::transaction(function () use ($agency, $user, $membership) {
                $municipality = DB::table('municipalities as m')->join('municipality_catalogs as mc', 'mc.municipality_id', '=', 'm.id')
                    ->join('catalog_releases as cr', 'cr.id', '=', 'mc.catalog_release_id')->where('cr.status', 'active')
                    ->orderBy('m.name')->first(['m.id', 'm.name', 'm.territorial_province_id']);
                $units = DB::table('cadastral_unit_versions as v')->join('catalog_releases as cr', 'cr.id', '=', 'v.catalog_release_id')
                    ->join('municipality_catalogs as mc', 'mc.catalog_release_id', '=', 'cr.id')
                    ->join('cadastral_units as cu', 'cu.id', '=', 'v.cadastral_unit_id')->join('parcels as p', 'p.id', '=', 'cu.parcel_id')
                    ->where('cr.status', 'active')->where('v.status', 'eligible')->where('p.municipality_id', $municipality?->id)
                    ->where('p.cadastral_kind', 'F')->orderBy('v.cadastral_unit_id')->limit(8)->get(['v.cadastral_unit_id']);

                $clientRows = [
                    ['Ada', 'Rossi', '+39 333 510 0101', 'ada.rossi@demo.reko.test'],
                    ['Luca', 'Bianchi', '+39 333 510 0102', 'luca.bianchi@demo.reko.test'],
                    ['Marta', 'Ferrero', '+39 333 510 0103', 'marta.ferrero@demo.reko.test'],
                    ['Paolo', 'Conti', '+39 333 510 0104', 'paolo.conti@demo.reko.test'],
                    ['Sara', 'Marino', '+39 333 510 0105', 'sara.marino@demo.reko.test'],
                ];
                $clients = $requests = $properties = $activities = $linkedUnits = 0;
                foreach ($clientRows as $index => [$given, $family, $phone, $email]) {
                    $client = Contact::query()->where('agency_id', $agency->id)->whereHas('primaryEmail', fn ($q) => $q->where('normalized_value', strtolower($email)))->first();
                    if (! $client) {
                        $client = app(SaveClient::class)->handle($membership, [
                            'name' => "DEMO · {$given} {$family}", 'phone' => $phone, 'email' => $email,
                            'status' => $index % 2 ? 'Attivo' : 'Da profilare', 'source' => 'Dati demo sintetici',
                            'preferred_channel' => 'Telefono', 'consent_practice' => true, 'consent_marketing' => false,
                        ]);
                        $clients++;
                    }

                    $request = PropertyRequest::query()->where('agency_id', $agency->id)->where('contact_id', $client->id)->first();
                    if (! $request) {
                        $request = app(SavePropertyRequest::class)->handle($membership, [
                            'contact_id' => $client->id,
                            'quick' => [
                                'operation' => $index % 2 ? 'Locazione' : 'Acquisto',
                                'typology' => $index % 2 ? 'Appartamento' : 'Casa indipendente',
                                'zone' => (string) ($municipality?->name ?? 'Comune demo'),
                                'budgetMin' => 90000 + ($index * 10000), 'budgetMax' => 220000 + ($index * 15000),
                                'details' => ['purpose' => 'Abitazione principale', 'area' => ['min' => 55 + ($index * 5), 'max' => 145]],
                            ],
                        ]);
                        $request = app(FinishPropertyRequest::class)->handle($membership, $request, Commands::revision($request));
                        $requests++;
                    }

                    if (! $municipality) continue;
                    $property = Property::query()->where('agency_id', $agency->id)->where('title', "DEMO · Immobile ".($index + 1))->first();
                    if (! $property) {
                        $unitId = $units[$index]->cadastral_unit_id ?? null;
                        $price = 125000 + ($index * 24000);
                        $property = app(SaveProperty::class)->handle($membership, [
                            'agent_user_id' => $user->id, 'municipality_id' => $municipality->id,
                            'title' => 'DEMO · Immobile '.($index + 1), 'address' => 'Via Esempio '.($index + 10),
                            'city' => $municipality->name, 'province' => null, 'civic' => (string) ($index + 1),
                            'zone' => $municipality->name, 'status' => 'Attivo',
                            'features' => [
                                'operation' => $index % 2 ? 'Locazione' : 'Acquisto',
                                'typology' => $index % 2 ? 'Appartamento' : 'Casa indipendente',
                                'price' => $price, 'area' => 65 + ($index * 8), 'rooms' => 3 + ($index % 2),
                                'bedrooms' => 2, 'bathrooms' => 1, 'condition' => 'Buono', 'tags' => ['demo'],
                            ],
                            'description' => 'Annuncio dimostrativo: informazioni inventate per provare il Gestionale.',
                            'internal_notes' => 'Dati sintetici generati per il database di test.',
                            'cadastral_unit_ids' => $unitId ? [$unitId] : [],
                        ]);
                        $properties++;
                        if ($unitId) $linkedUnits++;
                    }
                }

                $activityRows = [
                    ['Chiamata demo · confermare preferenze', now()->addHours(2)],
                    ['Visita demo · immobile 1', now()->addDay()],
                    ['Email demo · invio riepilogo', now()->addDays(2)],
                    ['Promemoria demo · aggiornare richiesta', null],
                ];
                foreach ($activityRows as $index => [$subject, $scheduled]) {
                    if (Activity::query()->where('agency_id', $agency->id)->where('subject', $subject)->exists()) continue;
                    $contact = Contact::query()->where('agency_id', $agency->id)->where('display_name', 'like', 'DEMO ·%')->orderBy('id')->skip($index)->first();
                    $property = Property::query()->where('agency_id', $agency->id)->where('title', 'like', 'DEMO ·%')->orderBy('id')->skip($index)->first();
                    $activity = new Activity;
                    $activity->forceFill([
                        'agency_id' => $agency->id, 'user_id' => $user->id, 'created_by_user_id' => $user->id,
                        'assigned_to_user_id' => $user->id, 'contact_id' => $contact?->id, 'property_id' => $property?->id,
                        'kind' => ['Chiamata', 'Visita', 'Email', 'Promemoria'][$index], 'subject' => $subject,
                        'status' => 'Da svolgere', 'priority' => $index === 0 ? 'Alta' : 'Normale', 'scheduled_at' => $scheduled,
                        'visibility' => 'workflow', 'notes' => 'Attività sintetica di prova. Nessun messaggio è stato inviato.', 'metadata' => (object) [],
                    ])->save();
                    app(Audit::class)->record('activity.create', $activity, ['demo' => true]);
                    $activities++;
                }

                if ($municipality && ! ScoutingZone::query()->where('agency_id', $agency->id)->where('name', 'DEMO · '. $municipality->name)->exists()) {
                    $zone = new ScoutingZone;
                    $zone->forceFill(['agency_id' => $agency->id, 'operator_user_id' => $user->id, 'name' => 'DEMO · '.$municipality->name,
                        'notes' => 'Zona dimostrativa basata sul confine comunale del catalogo.', 'status' => 'Pianificata',
                        'starts_on' => today(), 'municipalities' => DB::table('municipalities')->where('id', $municipality->id)->pluck('cadastral_code')->all()])->save();
                    (new ScoutingZoneAssignment)->forceFill(['agency_id' => $agency->id, 'scouting_zone_id' => $zone->id, 'user_id' => $user->id])->save();
                    DB::update('UPDATE scouting_zones SET boundary = (SELECT boundary FROM municipalities WHERE id = ?) WHERE id = ? AND agency_id = ?', [$municipality->id, $zone->id, $agency->id]);
                    app(Audit::class)->record('scouting-zone.create', $zone, ['demo' => true]);
                }

                return ['clients' => $clients, 'requests' => $requests, 'properties' => $properties, 'activities' => $activities, 'units' => $linkedUnits];
            });
        } finally {
            if ($oldUser) Auth::login($oldUser); else Auth::logout();
        }
    }
}
