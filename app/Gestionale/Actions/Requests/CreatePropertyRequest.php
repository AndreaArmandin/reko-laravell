<?php

namespace App\Gestionale\Actions\Requests;

use App\Gestionale\Actions\Clients\SaveClient;
use App\Gestionale\Clients\ClientMatcher;
use App\Gestionale\CommandRejected;
use App\Gestionale\Commands;
use App\Gestionale\Idempotency;
use App\Models\AgencyMembership;
use App\Models\PropertyRequest;
use Illuminate\Support\Facades\DB;

/**
 * request.create of the gestionale (engine.ts:129-146): the only entry point for a new request,
 * with an existing client or a new one, in ONE transaction (a new client never stays behind if
 * linking the request fails). Same phone AND email reuse the client; partial matches and
 * homonyms must be verified first. Audit: 'client.save' (new client only) + 'request.save'.
 */
final class CreatePropertyRequest
{
    public function __construct(
        private readonly SaveClient $saveClient,
        private readonly SavePropertyRequest $saveRequest,
        private readonly Idempotency $idempotency,
    ) {}

    /**
     * @param  array<string, mixed>  $input  contact_id | client{name, phone, email, consent_practice, consent_marketing},
     *                                       confirm_homonym, quick, idempotency_key
     */
    public function handle(AgencyMembership $actor, array $input): PropertyRequest
    {
        Commands::gate($actor);

        if (is_string($input['idempotency_key'] ?? null) && $input['idempotency_key'] !== '') {
            $response = $this->idempotency->run('request.create', $input['idempotency_key'], array_diff_key($input, ['idempotency_key' => true]),
                fn () => ['property_request_id' => $this->create($actor, $input)->id]);

            return PropertyRequest::query()->findOrFail($response['property_request_id']);
        }

        return DB::transaction(fn () => $this->create($actor, $input));
    }

    /** @param array<string, mixed> $input */
    private function create(AgencyMembership $actor, array $input): PropertyRequest
    {
        $contactId = $input['contact_id'] ?? null;

        if (! $contactId) {
            $client = is_array($input['client'] ?? null) ? $input['client'] : [];
            $name = Commands::text($client['name'] ?? '', 120);
            $phone = Commands::text($client['phone'] ?? '', 60);
            $email = Commands::text($client['email'] ?? '', 160);
            $matches = ClientMatcher::matches($name, $phone, $email);
            $exact = $matches->filter(fn ($m) => $m->exact());

            if ($exact->count() > 1) {
                throw new CommandRejected('Gli stessi recapiti sono presenti in più schede. Verifica il cliente e apri la richiesta esistente.', 409, 'client');
            }
            if ($exact->count() === 1) {
                $contactId = Commands::requireClient($actor, $exact->first()->contact)->id;
            } else {
                if ($matches->contains(fn ($m) => $m->phone || $m->email)) {
                    throw new CommandRejected('Cellulare o email già presenti: verifica entrambi i recapiti e apri la scheda esistente. Nessun duplicato creato.', 409, 'client');
                }
                if ($matches->contains(fn ($m) => $m->name) && ($phone === '' || $email === '' || ($input['confirm_homonym'] ?? false) !== true)) {
                    throw new CommandRejected('Omonimia: completa e verifica cellulare ed email, poi conferma che appartengono a una persona diversa.', 409, 'client');
                }
                $contactId = $this->saveClient->handle($actor, [
                    'name' => $client['name'] ?? '',
                    'phone' => $client['phone'] ?? '',
                    'email' => $client['email'] ?? '',
                    'consent_practice' => (bool) ($client['consent_practice'] ?? false),
                    'consent_marketing' => (bool) ($client['consent_marketing'] ?? false),
                    'confirm_duplicate' => false,
                ])->id;
            }
        }

        return $this->saveRequest->handle($actor, array_filter([
            'contact_id' => $contactId,
            'title_auto' => true,
            'quick' => $input['quick'] ?? null,
        ], fn ($v, $k) => $k !== 'quick' || array_key_exists('quick', $input), ARRAY_FILTER_USE_BOTH));
    }
}
