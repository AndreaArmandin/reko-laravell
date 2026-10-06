<?php

namespace App\Gestionale\Actions\Requests;

use App\Events\Gestionale\PropertyRequestCriteriaChanged;
use App\Gestionale\Actions\Clients\SaveClient;
use App\Gestionale\Audit;
use App\Gestionale\Clients\ClientMatcher;
use App\Gestionale\CommandRejected;
use App\Gestionale\Commands;
use App\Gestionale\Questionnaire\AnswerValidator;
use App\Gestionale\Questionnaire\Questionnaire;
use App\Gestionale\Questionnaire\QuickRequestCriteria;
use App\Models\AgencyMembership;
use App\Models\ClientProfile;
use App\Models\Contact;
use App\Models\PropertyRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * request.save of the gestionale (engine.ts:172-192): new request (from CreatePropertyRequest) or
 * "Gestisci richiesta" (change of client or status). One request per client identity, checked
 * under row locks (no DB UNIQUE: legacy data may hold more than one). Any valid status may be set.
 * priority / priority_reason / next_action / due_date are ignored (legacy, they belong to activities).
 */
final class SavePropertyRequest
{
    public const ONE_PER_CLIENT = 'Questo cliente ha già una richiesta: aprila e modifica le esigenze esistenti. Nessun duplicato creato.';

    public function __construct(private readonly Audit $audit) {}

    /**
     * @param  array<string, mixed>  $input  contact_id, status, title, title_auto, quick, expected_updated_at (edit)
     */
    public function handle(AgencyMembership $actor, array $input, ?PropertyRequest $request = null): PropertyRequest
    {
        Commands::gate($actor);
        if ($request !== null) {
            Commands::requireRequest($actor, $request);
            if (array_key_exists('quick', $input)) {
                throw new CommandRejected('La richiesta rapida crea una bozza nuova: modifica quella esistente dalla profilazione.', 400, 'quick');
            }
        }

        $quick = null;
        if (array_key_exists('quick', $input)) {
            $questionnaire = Questionnaire::forAgency((int) $actor->agency_id);
            $quick = QuickRequestCriteria::from($input['quick'], $questionnaire);
            $visible = array_column($questionnaire->visible($quick), null, 'id');
            foreach ($quick as $key => $value) {
                if (! isset($visible[$key])) {
                    throw new CommandRejected('Questi dati richiedono il profilo completo.', 400, 'quick');
                }
                $quick[$key] = AnswerValidator::validate($value, $visible[$key]);
            }
        }

        return DB::transaction(function () use ($actor, $input, $request, $quick) {
            $old = null;
            if ($request !== null) {
                $old = PropertyRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();
                Commands::assertRevision($old, $input['expected_updated_at'] ?? null);
            }

            $contactId = $input['contact_id'] ?? $old?->contact_id;
            $client = Commands::requireClient($actor, $contactId ? Contact::query()->with('clientProfile')->find($contactId) : null);

            if ($old === null || (int) $old->contact_id !== (int) $client->id) {
                self::assertNoOtherRequest($client, $old?->id);
            }

            $status = (string) ($input['status'] ?? $old?->status ?? 'Nuova');
            if (! in_array($status, PropertyRequest::STATUSES, true)) {
                throw new CommandRejected('Stato richiesta non valido.', 400, 'status');
            }

            $saved = $old ?? new PropertyRequest([
                'criteria' => [],
                'classifications' => [],
                'step_id' => 'operation',
                'finished' => false,
                'priority' => 'Normale',
                'title_auto' => (bool) ($input['title_auto'] ?? false),
            ]);
            $before = $old?->only(['contact_id', 'agent_user_id', 'status', 'title']) ?? [];
            $saved->fill([
                'contact_id' => $client->id,
                'agent_user_id' => $client->clientProfile->agent_user_id,
                'title' => Commands::text($input['title'] ?? $old?->title ?? 'Richiesta di '.$client->display_name),
                'status' => $status,
            ]);
            if ($quick !== null) {
                $saved->fill(['criteria' => $quick, 'finished' => false, 'status' => 'Da completare', 'step_id' => 'purpose',
                    'title_auto' => true, 'title' => Questionnaire::autoTitle($quick)]);
            }
            $saved->updated_at = now();
            $saved->save();

            if ($quick !== null || $old === null) {
                app(\App\Gestionale\Properties\PropertyMatcher::class)->refreshRequest($saved);
            }

            PropertyRequestCriteriaChanged::dispatch($saved->id);
            $this->audit->record('request.save', $saved, ['changed' => SaveClient::diff($before, $saved->only(['contact_id', 'agent_user_id', 'status', 'title']))]);

            return $saved;
        });
    }

    /**
     * identity.ts existingRequests(): the client and the clients with the same primary phone AND email
     * may hold only one request. Locks their client_profiles rows so two concurrent creations serialize.
     */
    public static function assertNoOtherRequest(Contact $client, ?int $exceptRequestId = null): void
    {
        $client->loadMissing(['primaryPhone', 'primaryEmail']);
        $identityIds = ClientMatcher::matches(null, $client->primaryPhone?->value, $client->primaryEmail?->value)
            ->filter(fn ($m) => $m->exact())
            ->map(fn ($m) => (int) $m->contact->id)
            ->push((int) $client->id)->unique()->sort()->values()->all();

        ClientProfile::query()->whereIn('contact_id', $identityIds)->orderBy('contact_id')->lockForUpdate()->get(['id']);

        if (PropertyRequest::query()->whereIn('contact_id', $identityIds)->when($exceptRequestId, fn ($q) => $q->whereKeyNot($exceptRequestId))->exists()) {
            throw new CommandRejected(self::ONE_PER_CLIENT, 409, 'contact_id');
        }
    }

    /**
     * Existing requests of the client identity, most recent first (to offer "Apri <titolo>").
     *
     * @return Collection<int, PropertyRequest>
     */
    public static function existingFor(Contact $client)
    {
        $client->loadMissing(['primaryPhone', 'primaryEmail']);
        $ids = ClientMatcher::matches(null, $client->primaryPhone?->value, $client->primaryEmail?->value)
            ->filter(fn ($m) => $m->exact())->map(fn ($m) => (int) $m->contact->id)->push((int) $client->id)->unique()->all();

        return PropertyRequest::query()->whereIn('contact_id', $ids)->orderByDesc('updated_at')->orderByDesc('id')->get();
    }
}
