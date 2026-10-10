<?php

namespace App\Gestionale\Actions\Census;

use App\Gestionale\Audit;
use App\Gestionale\Census\CensusReader;
use App\Gestionale\Census\SisterText;
use App\Gestionale\CommandRejected;
use App\Gestionale\Commands;
use App\Models\AgencyMembership;
use Illuminate\Support\Facades\DB;

/**
 * census.contact: il recapito del proprietario è un campo libero (telefono, WhatsApp, email o annotazione)
 * e vale per tutte le sue unità. Ogni variazione resta nella cronologia (prima / dopo).
 */
final class SaveOwnerContact extends CensusCommand
{
    public function __construct(private readonly Audit $audit) {}

    /** @return bool true se qualcosa è cambiato */
    public function handle(AgencyMembership $actor, int $contactId, array $input): bool
    {
        self::gate($actor);

        return DB::transaction(function () use ($actor, $contactId, $input) {
            $contact = self::owner($actor, $contactId);
            $locked = $contact->newQuery()->whereKey($contact->id)->lockForUpdate()->firstOrFail();
            if (isset($input['expected_updated_at'])) {
                Commands::assertRevision($locked, $input['expected_updated_at']);
            }
            if (array_diff(array_keys($input), ['recapito', 'confirmRepeated', 'expected_updated_at']) !== [] || ! is_string($input['recapito'] ?? null) || mb_strlen($input['recapito']) > 10000) {
                throw new CommandRejected('Inserisci il recapito nel campo libero, entro 10.000 caratteri.', 400, 'recapito');
            }
            $owner = CensusReader::owner((object) $locked->getAttributes(), DB::table('contact_channels')->where('contact_id', $locked->id)->where('kind', 'phone')->get());
            $before = CensusReader::contactText($owner);
            $after = $input['recapito'];
            if ($before === $after) {
                return false;
            }
            $contacts = SisterText::extractedContacts($after);
            if (count(array_unique($contacts)) !== count($contacts) && ($input['confirmRepeated'] ?? false) !== true) {
                throw new CommandRejected('Lo stesso recapito compare più volte nel testo. Verifica e mantieni una sola occorrenza oppure conferma che la ripetizione è intenzionale.', 400, 'recapito');
            }
            $history = $locked->contact_history ?? [];
            $history[] = ['at' => now()->toIso8601String(), 'actorId' => $actor->user_id, 'before' => $before, 'after' => $after];
            $locked->forceFill(['recapito' => $after, 'contact_history' => $history])->save();
            $this->audit->record('census.contact', $locked, ['before' => $before, 'after' => $after]);

            return true;
        });
    }
}
