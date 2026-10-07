<?php

namespace App\Gestionale\Actions\Properties;

use App\Gestionale\Audit;
use App\Gestionale\CommandRejected;
use App\Gestionale\Commands;
use App\Gestionale\Properties\PropertyMatcher;
use App\Models\AgencyMembership;
use App\Models\CadastralUnit;
use App\Models\Property;
use App\Models\PropertyUnit;
use Illuminate\Support\Facades\DB;

final class SaveProperty
{
    public const STATUSES = Property::STATUSES;

    public function __construct(private readonly Audit $audit) {}

    /** @param array<string,mixed> $input */
    public function handle(AgencyMembership $actor, array $input, ?Property $property = null): Property
    {
        if (! $actor->isActive() || ! in_array($actor->role, ['admin', 'crm'], true)) {
            throw new CommandRejected('La scheda immobile è riservata al Responsabile e alla Segreteria.', 403);
        }
        if ($property && ((int) $property->agency_id !== (int) $actor->agency_id
            || ($actor->role === 'crm' && (int) $property->agent_user_id !== (int) $actor->user_id))) {
            throw new CommandRejected(Commands::RECORD_DENIED, 403);
        }

        $title = Commands::text($input['title'] ?? $property?->title, 160);
        $address = Commands::text($input['address'] ?? $property?->address, 255);
        $city = Commands::text($input['city'] ?? $property?->municipality?->name, 120);
        if ($title === '' || $address === '' || $city === '') {
            throw new CommandRejected('Inserisci titolo, indirizzo e Comune dell’immobile.', 400);
        }

        $features = $input['features'] ?? $property?->features ?? [];
        if (! is_array($features)) {
            throw new CommandRejected('Caratteristiche dell’immobile non valide.', 400, 'features');
        }
        $mandate = is_array($input['mandate'] ?? null) ? $input['mandate'] : ($property?->mandate ?? []);
        $publication = is_array($input['publication'] ?? null) ? $input['publication'] : ($property?->publication ?? []);
        $url = $publication['url'] ?? null;
        if ($url !== null && $url !== '' && (! is_string($url) || ! preg_match('#^https?://[^\s]+$#i', $url) || mb_strlen($url) > 2000)) {
            throw new CommandRejected('Il link dell’annuncio deve iniziare con http:// o https://.', 400, 'publication.url');
        }
        // These JSONB columns are maps (objects), so an empty PHP list must not
        // be encoded as the JSON array [] and rejected by the database check.
        $mandate = $mandate === [] ? (object) [] : $mandate;
        $publication = $publication === [] ? (object) [] : $publication;
        if (! in_array($features['operation'] ?? null, ['Acquisto', 'Locazione'], true)) {
            throw new CommandRejected('Scegli vendita o locazione.', 400, 'operation');
        }
        foreach (['price', 'area', 'walkableArea', 'fees', 'rooms', 'bedrooms', 'bathrooms'] as $key) {
            if (isset($features[$key]) && (! is_numeric($features[$key]) || (float) $features[$key] < 0 || ! is_finite((float) $features[$key]))) {
                throw new CommandRejected('Controlla il campo '.$key.': inserisci un numero valido.', 400, $key);
            }
        }

        $status = (string) ($input['status'] ?? $property?->status ?? 'Non attivo');
        if (! in_array($status, self::STATUSES, true)
            || ($status === 'Locato' && $features['operation'] !== 'Locazione')
            || ($status === 'Venduto' && $features['operation'] !== 'Acquisto')) {
            throw new CommandRejected('Lo stato selezionato non è compatibile con il contratto.', 400, 'status');
        }

        $agentId = $actor->isAdmin() ? (int) ($input['agent_user_id'] ?? $property?->agent_user_id ?? $actor->user_id) : (int) $actor->user_id;
        if (! AgencyMembership::query()->where('agency_id', $actor->agency_id)->where('user_id', $agentId)->active()->exists()) {
            throw new CommandRejected('Scegli un referente attivo dell’agenzia.', 400, 'agent_user_id');
        }

        // Without the key the existing links stay as they are (editing a property must not wipe them)
        $replaceUnits = $property === null || array_key_exists('cadastral_unit_ids', $input);
        $unitIds = $input['cadastral_unit_ids'] ?? [];
        if (! is_array($unitIds) || count($unitIds) > 100 || count(array_unique(array_map('intval', $unitIds))) !== count($unitIds)) {
            throw new CommandRejected('Seleziona unità catastali valide.', 400, 'cadastral_unit_ids');
        }
        $unitIds = array_values(array_map('intval', $unitIds));
        if ($unitIds !== []) {
            $units = CadastralUnit::query()->join('parcels', 'parcels.id', '=', 'cadastral_units.parcel_id')
                ->whereIn('cadastral_units.id', $unitIds)
                ->get(['cadastral_units.id', 'parcels.municipality_id']);
            $targetMunicipality = $input['municipality_id'] ?? $property?->municipality_id ?? $units->first()?->municipality_id;
            if ($units->count() !== count($unitIds) || $units->contains(fn ($unit) => (int) $unit->municipality_id !== (int) $targetMunicipality)) {
                throw new CommandRejected('Le unità selezionate devono appartenere al Comune dell’immobile ed essere ancora presenti nel catalogo.', 409, 'cadastral_unit_ids');
            }
        }

        $duplicate = Property::query()->when($property, fn ($query) => $query->whereKeyNot($property->getKey()))
            ->whereRaw('lower(trim(address)) = ?', [mb_strtolower($address)])
            ->whereRaw('lower(trim(coalesce(civic, \'\'))) = ?', [mb_strtolower(Commands::text($input['civic'] ?? $property?->civic, 40))])
            ->where('municipality_id', $input['municipality_id'] ?? $property?->municipality_id)
            ->whereRaw("features->>'operation' = ?", [$features['operation']])->exists();
        if ($duplicate && ($input['confirm_duplicate'] ?? false) !== true) {
            throw new CommandRejected('Possibile immobile duplicato: indirizzo, civico e contratto già presenti. Verifica la scheda o conferma che si tratta di un immobile distinto.', 409, 'confirm_duplicate');
        }

        return DB::transaction(function () use ($actor, $input, $property, $title, $address, $city, $features, $mandate, $publication, $status, $agentId, $unitIds, $replaceUnits) {
            $before = $property?->only(['title', 'status', 'address', 'civic', 'asking_price', 'features', 'description', 'strengths', 'internal_notes']);
            $property ??= new Property;
            $property->fill([
                'agent_user_id' => $agentId,
                'municipality_id' => $input['municipality_id'] ?? $property->municipality_id,
                'title' => $title,
                'status' => $status,
                'address' => $address,
                'civic' => Commands::text($input['civic'] ?? $property->civic, 40) ?: null,
                'city' => $city,
                'province' => strtoupper(Commands::text($input['province'] ?? $property->province, 2)) ?: null,
                'postal_code' => Commands::text($input['postal_code'] ?? $property->postal_code, 12) ?: null,
                'zone' => Commands::text($input['zone'] ?? $property->zone, 160) ?: null,
                'asking_price' => $features['price'] ?? null,
                'features' => $features,
                'description' => Commands::text($input['description'] ?? $property->description, 12000) ?: null,
                'strengths' => Commands::text($input['strengths'] ?? $property->strengths, 4000) ?: null,
                'internal_notes' => Commands::text($input['internal_notes'] ?? $property->internal_notes, 4000) ?: null,
                'mandate' => $mandate,
                'publication' => $publication,
            ]);
            if ($property->exists) {
                $property->updated_at = now();
            }
            $property->save();

            if (isset($input['latitude'], $input['longitude']) && is_numeric($input['latitude']) && is_numeric($input['longitude'])) {
                $lat = (float) $input['latitude'];
                $lng = (float) $input['longitude'];
                if (abs($lat) > 90 || abs($lng) > 180) {
                    throw new CommandRejected('Coordinate non valide.', 400, 'latitude');
                }
                DB::statement('UPDATE properties SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326) WHERE agency_id = ? AND id = ?', [$lng, $lat, $actor->agency_id, $property->id]);
            }

            if ($replaceUnits) {
                PropertyUnit::query()->where('property_id', $property->id)->delete();
                foreach ($unitIds as $unitId) {
                    $link = new PropertyUnit;
                    $link->forceFill(['agency_id' => $actor->agency_id, 'property_id' => $property->id, 'cadastral_unit_id' => $unitId])->save();
                }
            }

            app(PropertyMatcher::class)->refreshProperty($property);

            $this->audit->record('property.save', $property, [
                'before' => $before,
                'after' => $property->only(['title', 'status', 'address', 'civic', 'asking_price', 'features', 'description', 'strengths', 'internal_notes']),
                'reason' => Commands::text($input['reason'] ?? '', 1000) ?: null,
            ], Commands::text($input['reason'] ?? '', 1000) ?: null);

            return $property->fresh(['units.cadastralUnit.parcel', 'municipality']);
        });
    }
}
