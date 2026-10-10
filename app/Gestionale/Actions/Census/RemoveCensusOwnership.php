<?php

namespace App\Gestionale\Actions\Census;

use App\Gestionale\Audit;
use App\Gestionale\CommandRejected;
use App\Models\AgencyMembership;
use App\Models\Contact;
use App\Models\Ownership;
use Illuminate\Support\Facades\DB;

/** Logical unlink / owner removal. Ownership rows and their histories are never physically deleted. */
final class RemoveCensusOwnership extends CensusCommand
{
    public function __construct(private readonly Audit $audit) {}

    public function unlink(AgencyMembership $actor, int $unitId, int $ownerId, array $input): Ownership
    {
        self::gate($actor);
        self::admin($actor);
        $reason = self::reason($input);
        if (($input['confirmed'] ?? false) !== true) {
            throw new CommandRejected('Conferma la variazione sulla scheda indicata.');
        }
        $effectiveDate = self::date($input['effectiveDate'] ?? null);

        return DB::transaction(function () use ($actor, $unitId, $ownerId, $reason, $effectiveDate, $input): Ownership {
            self::unit($actor, $unitId, true);
            self::owner($actor, $ownerId);
            $ownership = Ownership::query()->where('agency_id', $actor->agency_id)->where('cadastral_unit_id', $unitId)
                ->where('contact_id', $ownerId)->whereNull('valid_to')->lockForUpdate()->first();
            if (! $ownership) {
                throw new CommandRejected('Associazione non trovata.');
            }
            $before = $ownership->only(['valid_from', 'valid_to', 'details']);
            $details = $ownership->details ?? [];
            $details['reason'] = $reason;
            $details['kind'] = ($input['correction'] ?? false) === true ? 'Correzione' : 'Trasferimento';
            $details['exact_date_unknown'] = $effectiveDate === null;
            $ownership->forceFill(['valid_to' => $effectiveDate ?? now()->toDateString(), 'details' => $details])->save();
            $this->audit->record('census.unlink', $ownership, ['before' => $before, 'after' => $ownership->only(['valid_from', 'valid_to', 'details'])], $reason);

            return $ownership;
        });
    }

    public function removeOwner(AgencyMembership $actor, int $ownerId, array $input): Contact
    {
        self::gate($actor);
        self::admin($actor);
        if (($input['confirmed'] ?? false) !== true) {
            throw new CommandRejected('Conferma la variazione sulla scheda indicata.');
        }
        $reason = self::text($input['reason'] ?? '', 4000);

        return DB::transaction(function () use ($actor, $ownerId, $reason): Contact {
            $owner = Contact::query()->where('agency_id', $actor->agency_id)->whereKey($ownerId)->lockForUpdate()->first();
            if (! $owner || $owner->removed_at !== null) {
                throw new CommandRejected('Proprietario non trovato.', 404);
            }
            if (Ownership::query()->where('agency_id', $actor->agency_id)->where('contact_id', $ownerId)->whereNull('valid_to')->exists()) {
                throw new CommandRejected('Il proprietario ha ancora intestazioni attuali: chiudile o correggile singolarmente prima di rimuovere l’anagrafica.');
            }
            if (DB::table('client_profiles')->where('agency_id', $actor->agency_id)->where('contact_id', $ownerId)->exists()
                || DB::table('property_contacts')->where('agency_id', $actor->agency_id)->where('contact_id', $ownerId)->exists()) {
                throw new CommandRejected('Questa anagrafica è collegata anche al portafoglio CRM. Rimuovi prima i collegamenti commerciali dalla relativa scheda.');
            }
            $owner->forceFill(['removed_at' => now(), 'removed_by_user_id' => $actor->user_id, 'removed_reason' => $reason ?: 'Rimosso dall’archivio catastale'])->save();
            $this->audit->record('census.owner.delete', $owner, ['removed_at' => $owner->removed_at], $reason);

            return $owner;
        });
    }

    private static function date(mixed $value): ?string
    {
        $date = self::text($value, 10);
        if ($date === '') {
            return null;
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
            throw new CommandRejected('Controlla la data della variazione.', 400, 'effectiveDate');
        }

        return $date;
    }
}
