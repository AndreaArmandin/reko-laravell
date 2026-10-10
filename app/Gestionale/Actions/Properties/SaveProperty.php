<?php

namespace App\Gestionale\Actions\Properties;

use App\Events\Gestionale\PropertyPriceRevised;
use App\Events\Gestionale\PropertyPublished;
use App\Gestionale\Audit;
use App\Gestionale\CommandRejected;
use App\Gestionale\Commands;
use App\Gestionale\Idempotency;
use App\Gestionale\Properties\PropertyFields;
use App\Gestionale\Properties\PropertyLinks;
use App\Gestionale\Properties\PropertyMatcher;
use App\Gestionale\Properties\PropertyStatus;
use App\Models\AgencyMembership;
use App\Models\Municipality;
use App\Models\Property;
use App\Models\PropertyContact;
use App\Models\PropertyUnit;
use Illuminate\Support\Facades\DB;

/**
 * property.save of the gestionale (engine.ts:220-276), same rules and messages:
 * - only admin and crm; a crm edits only its own properties and is always the referent;
 * - title, address and Comune are required; the contract is vendita (Acquisto) or locazione;
 * - the status must be compatible with the contract (Locato only for rentals, Venduto only for sales);
 * - the referent is an active admin/crm member (an admin picks one);
 * - features, mandate and publication are whitelisted (Livewire lets the client alter arrays);
 * - the listing URL must be https; tags up to 30 of 60 characters;
 * - units and owners must be accessible to who saves; without the keys the links stay as they are;
 * - assigned scouts are changed only by an admin and must be active scouts of the agency;
 * - the position is kept unless a new point is given;
 * - possible duplicate (same address, civic, Comune and contract) needs an explicit confirmation;
 * - edits need expected_updated_at (428 if missing, 409 if stale); creations may carry an idempotency_key.
 * The mandate is not edited here (the form does not have it): it is kept on edit and starts empty
 * (type, exclusive, start, end, commission, reminderDays 15) on creation.
 */
final class SaveProperty
{
    public const STATUSES = Property::STATUSES;

    public const DEFAULT_PUBLICATION_STATUS = 'Non pubblicato dalla demo';

    public function __construct(private readonly Audit $audit, private readonly Idempotency $idempotency) {}

    /**
     * @param  array<string,mixed>  $input  title, code, address, civic, city, province, postal_code, zone, status, agent_user_id,
     *                                      features, mandate, publication{portals,status,date,url}, description, strengths, internal_notes,
     *                                      latitude, longitude, owner_ids, cadastral_unit_ids, assigned_scout_user_ids,
     *                                      confirm_duplicate, expected_updated_at (edit), idempotency_key (create)
     */
    public function handle(AgencyMembership $actor, array $input, ?Property $property = null): Property
    {
        if (! $actor->isActive() || ! in_array($actor->role, ['admin', 'crm'], true)) {
            throw new CommandRejected('La scheda immobile è riservata al Responsabile e alla Segreteria.', 403);
        }
        if ($property && ((int) $property->agency_id !== (int) $actor->agency_id
            || ($actor->role === 'crm' && (int) $property->agent_user_id !== (int) $actor->user_id))) {
            throw new CommandRejected(Commands::RECORD_DENIED, 403);
        }

        if ($property === null && is_string($input['idempotency_key'] ?? null) && $input['idempotency_key'] !== '') {
            $payload = array_diff_key($input, ['idempotency_key' => true]);
            $response = $this->idempotency->run('property.save', $input['idempotency_key'], $payload, fn () => ['property_id' => $this->save($actor, $input, null)->id]);

            return Property::query()->findOrFail($response['property_id']);
        }

        return DB::transaction(fn () => $this->save($actor, $input, $property));
    }

    /** @param array<string,mixed> $input */
    private function save(AgencyMembership $actor, array $input, ?Property $property): Property
    {
        $old = null;
        if ($property !== null) {
            $old = Property::query()->whereKey($property->getKey())->lockForUpdate()->firstOrFail();
            Commands::assertRevision($old, $input['expected_updated_at'] ?? null);
        }
        $value = fn (string $key, mixed $default = null) => array_key_exists($key, $input) ? $input[$key] : $default;

        $title = Commands::text($value('title', $old?->title), 160);
        $address = Commands::text($value('address', $old?->address), 255);
        $city = Commands::text($value('city', $old?->city), 120);
        if ($title === '' || $address === '' || $city === '') {
            throw new CommandRejected('Inserisci titolo, indirizzo e Comune dell’immobile.', 400);
        }

        $incoming = $value('features', $old?->features ?? []);
        if (! is_array($incoming)) {
            throw new CommandRejected('Caratteristiche dell’immobile non valide.', 400, 'features');
        }
        // Keys the form does not know (for example imported ones) stay as they were; the form ones are replaced by the cleaned input.
        $features = array_diff_key($old?->features ?? [], array_flip(PropertyFields::allKeys())) + PropertyFields::clean($incoming);
        if (! in_array($features['operation'] ?? null, ['Acquisto', 'Locazione'], true)) {
            throw new CommandRejected('Scegli vendita o locazione.', 400, 'features.operation');
        }
        $operation = $features['operation'];

        $status = (string) $value('status', $old?->status ?? 'Non attivo');
        if (! in_array($status, self::STATUSES, true)) {
            throw new CommandRejected('Stato immobile non valido.', 400, 'status');
        }
        if (! in_array($status, PropertyStatus::options($operation), true)) {
            throw new CommandRejected($status === 'Locato' ? 'Lo stato Locato è disponibile solo per gli immobili in affitto.' : 'Lo stato Venduto è disponibile solo per gli immobili in vendita.', 400, 'status');
        }

        $agentId = $actor->isAdmin() ? (int) $value('agent_user_id', $old?->agent_user_id ?? $actor->user_id) : (int) $actor->user_id;
        if (! AgencyMembership::query()->where('agency_id', $actor->agency_id)->where('user_id', $agentId)->whereIn('role', ['admin', 'crm'])->active()->exists()) {
            throw new CommandRejected('Scegli un referente attivo.', 400, 'agent_user_id');
        }

        $mandate = $this->mandate($value('mandate'), $old);
        $publication = $this->publication($value('publication', $old?->publication ?? []));
        if ($publication['url'] !== '' && ! preg_match('#^https://#i', $publication['url'])) {
            throw new CommandRejected('Usa un collegamento HTTPS per l’annuncio.', 400, 'publication.url');
        }

        $replaceUnits = $old === null || array_key_exists('cadastral_unit_ids', $input);
        $unitIds = $replaceUnits ? $this->ids($value('cadastral_unit_ids', []), 'Unità catastale non accessibile.') : [];
        if ($replaceUnits && PropertyLinks::inaccessibleUnits($actor, $unitIds, $old) !== []) {
            throw new CommandRejected('Unità catastale non accessibile.', 403, 'cadastral_unit_ids');
        }
        $replaceOwners = $old === null || array_key_exists('owner_ids', $input);
        $ownerIds = $replaceOwners ? $this->ids($value('owner_ids', []), 'Proprietario non accessibile.') : [];
        if ($replaceOwners && PropertyLinks::inaccessibleOwners($actor, $ownerIds, $old) !== []) {
            throw new CommandRejected('Proprietario non accessibile.', 403, 'owner_ids');
        }

        $scouts = $old?->assigned_scout_user_ids ?? [];
        if ($actor->isAdmin() && array_key_exists('assigned_scout_user_ids', $input)) {
            $ids = $input['assigned_scout_user_ids'];
            $ids = is_array($ids) ? array_values(array_unique(array_map('intval', $ids))) : null;
            if ($ids === null || count($ids) !== AgencyMembership::query()->where('agency_id', $actor->agency_id)->where('role', 'scout')->active()->whereIn('user_id', $ids)->count()) {
                throw new CommandRejected('Scegli operatori scouting validi.', 400, 'assigned_scout_user_ids');
            }
            $scouts = $ids;
        }

        $civic = Commands::text($value('civic', $old?->civic), 40);
        $duplicate = Property::query()->when($old, fn ($query) => $query->whereKeyNot($old->getKey()))
            ->whereRaw("regexp_replace(lower(coalesce(address, '') || coalesce(civic, '') || coalesce(city, '')), '[[:space:]+()-]', '', 'g') = ?", [$this->norm($address.$civic.$city)])
            ->whereRaw("features->>'operation' = ?", [$operation])->exists();
        if ($duplicate && ($input['confirm_duplicate'] ?? false) !== true) {
            throw new CommandRejected('Possibile immobile duplicato: indirizzo, civico e contratto già presenti. Verifica la scheda o conferma che si tratta di un immobile distinto.', 409, 'confirm_duplicate');
        }

        $point = null;
        if (isset($input['latitude'], $input['longitude']) && $input['latitude'] !== '' && $input['longitude'] !== '') {
            if (! is_numeric($input['latitude']) || ! is_numeric($input['longitude']) || abs((float) $input['latitude']) > 90 || abs((float) $input['longitude']) > 180) {
                throw new CommandRejected('Coordinate non valide.', 400, 'latitude');
            }
            $point = [(float) $input['latitude'], (float) $input['longitude']];
        }

        $code = Commands::text($value('code', $old?->code), 40);
        if ($code === '') {
            $code = 'DEMO-'.(Property::query()->count() + 1);
        }

        $audited = ['title', 'code', 'status', 'address', 'civic', 'asking_price', 'features', 'description', 'strengths', 'internal_notes', 'publication'];
        $before = $old?->only($audited);
        $property ??= new Property;
        $property->fill([
            'agent_user_id' => $agentId,
            'municipality_id' => $this->municipalityId($city) ?? $old?->municipality_id,
            'title' => $title,
            'code' => $code,
            'status' => $status,
            'address' => $address,
            'civic' => $civic ?: null,
            'city' => $city,
            'province' => strtoupper(Commands::text($value('province', $old?->province), 2)) ?: null,
            'postal_code' => Commands::text($value('postal_code', $old?->postal_code), 12) ?: null,
            'zone' => Commands::text($value('zone', $old?->zone), 160) ?: null,
            'asking_price' => $features['price'] ?? null,
            'features' => $features === [] ? (object) [] : $features,
            'description' => Commands::text($value('description', $old?->description), 12000) ?: null,
            'strengths' => Commands::text($value('strengths', $old?->strengths)) ?: null,
            'internal_notes' => Commands::text($value('internal_notes', $old?->internal_notes)) ?: null,
            'mandate' => $mandate === [] ? (object) [] : $mandate,
            'publication' => $publication,
            'assigned_scout_user_ids' => array_values($scouts),
        ]);
        if ($property->exists) {
            $property->updated_at = now();
        }
        $property->save();

        if ($point !== null) {
            DB::statement('UPDATE properties SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326) WHERE agency_id = ? AND id = ?', [$point[1], $point[0], $actor->agency_id, $property->id]);
        }

        if ($replaceUnits) {
            PropertyUnit::query()->where('property_id', $property->id)->delete();
            foreach ($unitIds as $unitId) {
                (new PropertyUnit)->forceFill(['agency_id' => $actor->agency_id, 'property_id' => $property->id, 'cadastral_unit_id' => $unitId])->save();
            }
        }
        if ($replaceOwners) {
            PropertyContact::query()->where('property_id', $property->id)->where('role', PropertyLinks::OWNER_ROLE)->whereNotIn('contact_id', $ownerIds)->delete();
            $present = PropertyContact::query()->where('property_id', $property->id)->where('role', PropertyLinks::OWNER_ROLE)->pluck('contact_id')->map(fn ($id) => (int) $id)->all();
            foreach (array_diff($ownerIds, $present) as $contactId) {
                (new PropertyContact)->forceFill(['agency_id' => $actor->agency_id, 'property_id' => $property->id, 'contact_id' => $contactId, 'role' => PropertyLinks::OWNER_ROLE])->save();
            }
        }

        app(PropertyMatcher::class)->refreshProperty($property);

        $this->audit->record('property.save', $property, ['before' => $before, 'after' => $property->only($audited)]);

        if ($publication['status'] === 'Pubblicato' && $publication['url'] !== '') {
            PropertyPublished::dispatch($property->id);
        }
        if ($old !== null && isset($features['price']) && is_numeric($features['price']) && ($old->features['price'] ?? null) !== $features['price']) {
            PropertyPriceRevised::dispatch($property->id, (float) $features['price']);
        }

        return $property->fresh(['units.cadastralUnit.parcel', 'municipality']);
    }

    /**
     * Mandate: kept on edit; only the known keys, with their types, if a caller gives one.
     *
     * @return array<string, mixed>
     */
    private function mandate(mixed $given, ?Property $old): array
    {
        $base = $old?->mandate ?: [];
        if (! is_array($given)) {
            return $old ? $base : ['type' => '', 'exclusive' => false, 'start' => '', 'end' => '', 'commission' => '', 'reminderDays' => 15];
        }
        $date = fn ($v) => $this->date($v);

        return [
            'type' => Commands::text($given['type'] ?? $base['type'] ?? '', 100),
            'exclusive' => (bool) ($given['exclusive'] ?? $base['exclusive'] ?? false),
            'start' => $date($given['start'] ?? $base['start'] ?? ''),
            'end' => $date($given['end'] ?? $base['end'] ?? ''),
            'commission' => Commands::text($given['commission'] ?? $base['commission'] ?? '', 100),
            'reminderDays' => max(0, min(3650, (int) ($given['reminderDays'] ?? $base['reminderDays'] ?? 15))),
        ];
    }

    /**
     * Publication: portals, status, date, url; updatedAt is always the time of the save.
     *
     * @return array{portals: string, status: string, date: string, updatedAt: string, url: string}
     */
    private function publication(mixed $given): array
    {
        $given = is_array($given) ? $given : [];
        $url = $given['url'] ?? '';
        if (! is_scalar($url) && $url !== null) {
            throw new CommandRejected('Usa un collegamento HTTPS per l’annuncio.', 400, 'publication.url');
        }
        $date = $given['date'] ?? '';

        return [
            'portals' => Commands::text(is_scalar($given['portals'] ?? '') ? ($given['portals'] ?? '') : '', 300),
            'status' => Commands::text((is_scalar($given['status'] ?? '') ? ($given['status'] ?? '') : '') ?: self::DEFAULT_PUBLICATION_STATUS, 160),
            'date' => $this->date($date),
            'updatedAt' => now()->toIso8601String(),
            'url' => Commands::text($url, 1500),
        ];
    }

    private function date(mixed $value): string
    {
        return is_string($value) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : '';
    }

    /** @return list<int> */
    private function ids(mixed $ids, string $message): array
    {
        if (! is_array($ids) || count($ids) > 500 || array_filter($ids, fn ($id) => ! is_numeric($id) || (int) $id <= 0) !== []) {
            throw new CommandRejected($message, 403);
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /** engine.ts norm(): lower case without spaces, plus signs, parentheses and hyphens. */
    private function norm(string $value): string
    {
        return (string) preg_replace('/[\s+()-]/u', '', mb_strtolower($value));
    }

    private function municipalityId(string $city): ?int
    {
        return Municipality::query()->whereRaw('lower(name) = ?', [mb_strtolower($city)])->value('id');
    }
}
