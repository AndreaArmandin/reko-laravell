<?php

namespace App\Gestionale\Actions\Census;

use App\Gestionale\Census\CensusQuery;
use App\Gestionale\Census\CensusScope;
use App\Gestionale\CommandRejected;
use App\Models\AgencyMembership;
use App\Models\AgencyUnitObservation;
use App\Models\Contact;
use Illuminate\Support\Facades\DB;

/**
 * Regole comuni dei comandi dell'archivio catastale (engine.ts riga 111-112 e census-actions.ts):
 * la Segreteria non vi accede, lo scout opera solo nelle particelle assegnate, il resto è del Responsabile.
 */
abstract class CensusCommand
{
    public const CRM_DENIED = 'Operazione riservata allo scouting o all’Amministratore.';

    public const ADMIN_ONLY = 'Operazione riservata all’Amministratore.';

    public const ADMIN_ONLY_ENGINE = 'Questa operazione è riservata all’amministratore.';

    public const REASON_REQUIRED = 'Indica il motivo: la variazione resterà nello storico.';

    /** engine.ts:112 — census.*, catalog.*, owner.save e archive.save non sono della Segreteria. */
    protected static function gate(AgencyMembership $actor): void
    {
        if (! $actor->isActive() || $actor->role === 'crm') {
            throw new CommandRejected(self::CRM_DENIED, 403);
        }
    }

    protected static function admin(AgencyMembership $actor, string $message = self::ADMIN_ONLY): void
    {
        if ($actor->role !== 'admin') {
            throw new CommandRejected($message, 403);
        }
    }

    /** requireUnit(): scheda visibile e attiva. */
    protected static function unit(AgencyMembership $actor, int $unitId, bool $lock = false): AgencyUnitObservation
    {
        if (! CensusScope::unitVisible($actor, $unitId)) {
            throw new CommandRejected('Unità non accessibile.', 403);
        }
        $query = AgencyUnitObservation::query()->where('cadastral_unit_id', $unitId);
        $overlay = ($lock ? $query->lockForUpdate() : $query)->first() ?? throw new CommandRejected('Unità non accessibile.', 403);
        if ($overlay->state !== 'Attivo' || ! empty($overlay->removed)) {
            throw new CommandRejected('Unità soppressa o eliminata: consultazione dello storico soltanto.');
        }

        return $overlay;
    }

    protected static function parcelOf(AgencyUnitObservation $unit): int
    {
        return (int) DB::table('cadastral_units')->where('id', $unit->cadastral_unit_id)->value('parcel_id');
    }

    protected static function owner(AgencyMembership $actor, int $contactId, string $message = 'Proprietario non accessibile.'): Contact
    {
        $contact = Contact::query()->whereKey($contactId)->first();
        if (! $contact || $contact->isRemoved() || ! CensusScope::ownerVisible($actor, $contactId)) {
            throw new CommandRejected($message, 403);
        }

        return $contact;
    }

    protected static function reason(array $input): string
    {
        $reason = mb_substr(trim((string) ($input['reason'] ?? '')), 0, 4000);
        if ($reason === '') {
            throw new CommandRejected(self::REASON_REQUIRED, 400, 'reason');
        }

        return $reason;
    }

    protected static function text(mixed $value, int $max = 4000): string
    {
        return mb_substr(trim((string) ($value ?? '')), 0, $max);
    }

    public static function outcomes(): array
    {
        return CensusQuery::outcomes();
    }
}
