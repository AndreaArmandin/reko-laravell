<?php

namespace App\Gestionale\Actions\Census;

use App\Gestionale\Audit;
use App\Gestionale\Census\SisterText;
use App\Gestionale\CommandRejected;
use App\Gestionale\Commands;
use App\Gestionale\Questionnaire\Tags;
use App\Models\AgencyMembership;
use App\Models\Contact;
use Illuminate\Support\Facades\DB;

/**
 * owner.save: anagrafica del proprietario (solo Responsabile). Il codice fiscale è unico nell'agenzia:
 * un duplicato rimanda alla scheda esistente. I recapiti non cambiano da qui.
 */
final class SaveOwner extends CensusCommand
{
    public function __construct(private readonly Audit $audit) {}

    /** @param array<string,mixed> $input name, pid, firstName, lastName, companyName, tags, notes, reason, expected_updated_at */
    public function handle(AgencyMembership $actor, int $contactId, array $input): Contact
    {
        self::gate($actor);
        self::admin($actor, self::ADMIN_ONLY_ENGINE);

        return DB::transaction(function () use ($actor, $contactId, $input) {
            $contact = Contact::query()->whereKey($contactId)->lockForUpdate()->first();
            if (! $contact || $contact->isRemoved()) {
                throw new CommandRejected('Proprietario non accessibile.', 403);
            }
            Commands::assertRevision($contact, $input['expected_updated_at'] ?? null);
            $name = self::text($input['name'] ?? '', 255);
            $pid = SisterText::normalizedCF(self::text($input['pid'] ?? '', 32));
            if ($name === '' || ! preg_match('/\p{L}/u', $name) || ! preg_match('/^[A-Z0-9-]{3,32}$/', $pid)) {
                throw new CommandRejected('Inserisci nominativo e codice fiscale o identificativo valido.', 400, 'name');
            }
            if (Contact::query()->where('tax_code', $pid)->whereKeyNot($contact->id)->exists()) {
                throw new CommandRejected('Questo codice fiscale è già associato a un altro proprietario. Apri la scheda esistente per evitare duplicati.', 400, 'pid');
            }
            $parts = [];
            foreach (['firstName' => 'given_name', 'lastName' => 'family_name', 'companyName' => 'company_name'] as $key => $column) {
                if (array_key_exists($key, $input)) {
                    $value = self::text($input[$key], 255);
                    if ($value !== '' && ! preg_match('/\p{L}/u', $value)) {
                        throw new CommandRejected('Nome, cognome e ragione sociale non possono contenere soltanto numeri o simboli.', 400, $key);
                    }
                    $parts[$column] = $value !== '' ? $value : null;
                }
            }
            $before = $contact->only(['display_name', 'tax_code', 'given_name', 'family_name', 'company_name', 'notes', 'tags']);
            $contact->forceFill(['display_name' => $name, 'tax_code' => $pid, 'tags' => Tags::normalize((array) ($input['tags'] ?? [])),
                'notes' => ($notes = self::text($input['notes'] ?? '', 10000)) !== '' ? $notes : null] + $parts);
            $contact->updated_at = now();
            $contact->save();
            $this->audit->record('owner.save', $contact, ['before' => $before, 'after' => $contact->only(array_keys($before))], self::text($input['reason'] ?? ''));

            return $contact;
        });
    }
}
