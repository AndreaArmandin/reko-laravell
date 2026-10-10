<?php

namespace App\Gestionale\Actions\Clients;

use App\Events\Gestionale\PropertyRequestCriteriaChanged;
use App\Gestionale\Audit;
use App\Gestionale\Clients\ClientMatcher;
use App\Gestionale\CommandRejected;
use App\Gestionale\Commands;
use App\Gestionale\ContactKeys;
use App\Gestionale\Idempotency;
use App\Models\Activity;
use App\Models\AgencyMembership;
use App\Models\ClientProfile;
use App\Models\Contact;
use App\Models\ContactChannel;
use App\Models\PropertyRequest;
use Illuminate\Support\Facades\DB;

/**
 * client.save of the gestionale (engine.ts:156-171), same rules and messages:
 * - scout → 403; editing someone else's client (crm) → 403;
 * - edits need expected_updated_at (428 if missing, 409 if stale); creations may carry an idempotency_key;
 * - contactErrors: name, phone 6–15 digits, valid email, at least one contact; unchanged
 *   historical values are not re-validated; first error only;
 * - same primary phone/email of another client of the agency (changed values only) → 409 unless confirmed;
 * - a non-admin is always the referent; an admin picks an active member;
 * - changing the referent moves the client's requests and activities too (same transaction);
 * - consents_updated_at is refreshed on every save; one audit entry 'client.save'.
 */
final class SaveClient
{
    public const DUPLICATE_MESSAGE = 'Possibile cliente duplicato: telefono o email già presenti. Cerca la scheda esistente oppure conferma la creazione separata.';

    public function __construct(private readonly Audit $audit, private readonly Idempotency $idempotency) {}

    /**
     * @param  array<string, mixed>  $input  name, phone, email, status, preferred_channel, contact_time, source,
     *                                       next_action, notes, agent_user_id, consent_practice, consent_marketing,
     *                                       investor (array|null: active, min, max, zones, groups, opportunity),
     *                                       confirm_duplicate, expected_updated_at (edit), idempotency_key (create)
     */
    public function handle(AgencyMembership $actor, array $input, ?Contact $client = null): Contact
    {
        Commands::gate($actor);
        if ($client !== null) {
            Commands::requireClient($actor, $client);
        }

        if ($client === null && is_string($input['idempotency_key'] ?? null) && $input['idempotency_key'] !== '') {
            $payload = array_diff_key($input, ['idempotency_key' => true]);
            $response = $this->idempotency->run('client.save', $input['idempotency_key'], $payload, fn () => ['contact_id' => $this->save($actor, $input, null)->id]);

            return Contact::query()->findOrFail($response['contact_id']);
        }

        return DB::transaction(fn () => $this->save($actor, $input, $client));
    }

    /** @param array<string, mixed> $input */
    private function save(AgencyMembership $actor, array $input, ?Contact $client): Contact
    {
        $old = null;
        if ($client !== null) {
            $old = ClientProfile::query()->whereKey($client->clientProfile->getKey())->lockForUpdate()->firstOrFail();
            Commands::assertRevision($old, $input['expected_updated_at'] ?? null);
            $client->load(['primaryPhone', 'primaryEmail'])->setRelation('clientProfile', $old);
        }

        $value = fn (string $key, mixed $default = null) => array_key_exists($key, $input) ? $input[$key] : $default;
        $text = Commands::text(...);

        $name = $text($value('name', $client?->display_name), 120);
        // contact-validation.ts checks the typed values (trimmed, never cut): "> 60" and "> 160" must be able to fire.
        $typedPhone = trim((string) $value('phone', $client?->primaryPhone?->value));
        $typedEmail = trim((string) $value('email', $client?->primaryEmail?->value));
        $phoneChanged = ! $client || $text($typedPhone) !== $text($client->primaryPhone?->value);
        $emailChanged = ! $client || $text($typedEmail) !== $text($client->primaryEmail?->value);

        self::validateContact($name, $typedPhone, $typedEmail, $phoneChanged, $emailChanged, $client === null);
        $phone = $text($typedPhone, 60);
        $email = $text($typedEmail, 160);

        $duplicate = ClientMatcher::matches(null, $phoneChanged ? $phone : null, $emailChanged ? $email : null, $client?->id)
            ->contains(fn ($m) => $m->phone || $m->email);
        if ($duplicate && ($input['confirm_duplicate'] ?? false) !== true) {
            throw new CommandRejected(self::DUPLICATE_MESSAGE, 409, 'duplicate');
        }

        $agentId = $actor->isAdmin() ? (int) ($value('agent_user_id', $old?->agent_user_id) ?: $actor->user_id) : (int) $actor->user_id;
        if (! AgencyMembership::query()->where('agency_id', $actor->agency_id)->where('user_id', $agentId)->active()->exists()) {
            throw new CommandRejected('Scegli un referente attivo.', 400, 'agent_user_id');
        }

        $status = (string) ($value('status', $old?->status) ?: 'Nuovo');
        if (! in_array($status, ClientProfile::STATUSES, true)) {
            throw new CommandRejected('Stato cliente non valido.', 400, 'status');
        }
        $channel = (string) ($value('preferred_channel', $old?->preferred_channel) ?: 'Telefono');
        if (! in_array($channel, ClientProfile::CHANNELS, true)) {
            throw new CommandRejected('Canale preferito non valido.', 400, 'preferred_channel');
        }
        $investor = array_key_exists('investor', $input) ? self::investorProfile($input['investor']) : null;

        $before = $client ? self::snapshot($client) : null;

        $client ??= new Contact(['origin' => 'manual']);
        $client->display_name = $name;
        $client->save();

        self::syncChannel($client, 'phone', $phone);
        self::syncChannel($client, 'email', $email);

        $profile = $old ?? new ClientProfile(['contact_id' => $client->id, 'created_by_user_id' => $actor->user_id]);
        $profile->fill([
            'agent_user_id' => $agentId,
            'status' => $status,
            'preferred_channel' => $channel,
            'contact_time' => $text($value('contact_time', $old?->contact_time)) ?: null,
            'source' => $text($value('source', $old?->source)) ?: null,
            'next_action' => $text($value('next_action', $old?->next_action)) ?: null,
            'notes' => $text($value('notes', $old?->notes)) ?: null,
            'consent_practice' => (bool) $value('consent_practice', $old?->consent_practice ?? false),
            'consent_marketing' => (bool) $value('consent_marketing', $old?->consent_marketing ?? false),
            'consents_updated_at' => now(),
        ]);
        if (array_key_exists('investor', $input)) {
            $profile->fill($investor ?? ['is_investor' => false, 'investor_budget_min' => null, 'investor_budget_max' => null,
                'investor_zones' => null, 'investor_groups' => [], 'investor_opportunity' => null]);
        }
        $agentChanged = $old !== null && $old->isDirty('agent_user_id');
        $profile->updated_at = now(); // always a new revision, even when nothing else changed
        $profile->save();

        // The referent follows the client (engine.ts:171): its requests are reassigned too.
        if ($agentChanged) {
            $ids = PropertyRequest::query()->where('contact_id', $client->id)->pluck('id');
            PropertyRequest::query()->whereKey($ids)->update(['agent_user_id' => $agentId, 'updated_at' => now()]);
            // ... and so are its activities (engine.ts:171: activity.agentId = agentId).
            Activity::query()->where('contact_id', $client->id)->update(['assigned_to_user_id' => $agentId, 'updated_at' => now()]);
            $ids->each(fn ($id) => PropertyRequestCriteriaChanged::dispatch((int) $id));
        }

        $client->unsetRelation('clientProfile')->unsetRelation('primaryPhone')->unsetRelation('primaryEmail');
        $this->audit->record('client.save', $profile, ['contact_id' => $client->id, 'changed' => self::diff($before ?? [], self::snapshot($client))]);

        return $client;
    }

    /**
     * investors.ts investorProfile(): budgets >= 0, min <= max, known cadastral groups.
     *
     * @return array<string, mixed>|null
     */
    public static function investorProfile(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (! is_array($value)) {
            throw new CommandRejected('Profilo investitore non valido.', 400, 'investor');
        }

        $min = $value['min'] ?? null;
        $max = $value['max'] ?? null;
        foreach ([$min, $max] as $n) {
            if ($n !== null && $n !== '' && (! is_numeric($n) || (float) $n < 0)) {
                throw new CommandRejected('Budget investitore non valido.', 400, 'investor');
            }
        }
        $min = $min === null || $min === '' ? null : (float) $min;
        $max = $max === null || $max === '' ? null : (float) $max;
        if ($min !== null && $max !== null && $min > $max) {
            throw new CommandRejected('Il budget minimo supera il massimo.', 400, 'investor');
        }

        $groups = $value['groups'] ?? [];
        $zones = $value['zones'] ?? '';
        $opportunity = $value['opportunity'] ?? '';
        if (! is_array($groups) || count($groups) > 12 || array_diff($groups, array_keys(ClientProfile::INVESTOR_GROUPS)) !== []
            || ! is_string($zones) || mb_strlen($zones) > 500 || ! is_string($opportunity) || mb_strlen($opportunity) > 4000
            || ! is_bool($value['active'] ?? null)) {
            throw new CommandRejected('Controlla i criteri dell’investitore.', 400, 'investor');
        }

        return [
            'is_investor' => $value['active'],
            'investor_budget_min' => $min,
            'investor_budget_max' => $max,
            'investor_zones' => trim($zones) ?: null,
            'investor_groups' => array_values(array_unique($groups)),
            'investor_opportunity' => trim($opportunity) ?: null,
        ];
    }

    /** contact-validation.ts contactErrors(): first error, in the order name → phone → email → contact. */
    public static function validateContact(string $name, string $phone, string $email, bool $phoneChanged, bool $emailChanged, bool $isNew): void
    {
        if ($name === '') {
            throw new CommandRejected('Inserisci il nome del cliente.', 400, 'name');
        }
        if ($phoneChanged && $phone !== '' && (! preg_match('/^\+?[\d\s()\.\/-]+$/', $phone) || ! preg_match('/^(?:\D*\d){6,15}\D*$/', $phone) || mb_strlen($phone) > 60)) {
            throw new CommandRejected('Inserisci un telefono con 6–15 cifre. Sono ammessi +, spazi, parentesi e trattini.', 400, 'phone');
        }
        if ($emailChanged && $email !== '' && (mb_strlen($email) > 160 || ! preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $email))) {
            throw new CommandRejected('Controlla l’indirizzo email, per esempio nome@example.it.', 400, 'email');
        }
        if ($phone === '' && $email === '' && ($isNew || $phoneChanged || $emailChanged)) {
            throw new CommandRejected('Inserisci almeno un recapito: telefono oppure email.', 400, 'phone');
        }
    }

    private static function syncChannel(Contact $contact, string $kind, string $value): void
    {
        $channel = ContactChannel::query()->where('contact_id', $contact->id)->where('kind', $kind)->where('is_primary', true)->first();

        if ($value === '') {
            $channel?->delete();

            return;
        }
        if ($channel !== null && $channel->value === $value) {
            return;
        }
        // The same number may already be stored as a secondary channel: promote it.
        $existing = ContactChannel::query()->where('contact_id', $contact->id)->where('kind', $kind)
            ->where('normalized_value', ContactKeys::channel($kind, $value))->first();
        if ($existing !== null && $channel !== null && ! $existing->is($channel)) {
            $channel->delete();
            $channel = $existing;
        }
        $channel ??= $existing ?? new ContactChannel(['contact_id' => $contact->id, 'kind' => $kind]);
        $channel->fill(['value' => $value, 'is_primary' => true])->save();
    }

    /** @return array<string, mixed> */
    private static function snapshot(Contact $contact): array
    {
        $contact->load(['clientProfile', 'primaryPhone', 'primaryEmail']);
        $p = $contact->clientProfile;

        return [
            'name' => $contact->display_name,
            'phone' => $contact->primaryPhone?->value,
            'email' => $contact->primaryEmail?->value,
            'agent_user_id' => $p?->agent_user_id,
            'status' => $p?->status,
            'preferred_channel' => $p?->preferred_channel,
            'contact_time' => $p?->contact_time,
            'source' => $p?->source,
            'next_action' => $p?->next_action,
            'notes' => $p?->notes,
            'consent_practice' => $p?->consent_practice,
            'consent_marketing' => $p?->consent_marketing,
            'is_investor' => $p?->is_investor,
        ];
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public static function diff(array $before, array $after): array
    {
        $changed = [];
        foreach ($after as $key => $value) {
            if (($before[$key] ?? null) !== $value) {
                $changed[$key] = [$before[$key] ?? null, $value];
            }
        }

        return $changed;
    }
}
