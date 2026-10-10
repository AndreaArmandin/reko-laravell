<?php

namespace App\Gestionale\Actions\Census;

use App\Gestionale\Audit;
use App\Gestionale\Census\CensusImporter;
use App\Gestionale\Census\Holding;
use App\Gestionale\CommandRejected;
use App\Models\AgencyMembership;
use App\Models\CadastralUnit;
use App\Models\Ownership;
use Illuminate\Support\Facades\DB;

/** Link an existing owner to an active unit, preserving the source and holding details. */
final class AssociateCensusOwner extends CensusCommand
{
    public function __construct(private readonly Audit $audit) {}

    public function handle(AgencyMembership $actor, int $unitId, int $ownerId, array $input): Ownership
    {
        self::gate($actor);

        return DB::transaction(function () use ($actor, $unitId, $ownerId, $input): Ownership {
            $overlay = self::unit($actor, $unitId, true);
            if ($actor->role === 'scout' && CensusScope::parcelOperator((int) $actor->agency_id, self::parcelOf($overlay)) !== (int) $actor->user_id) {
                throw new CommandRejected('Associazione consentita soltanto nelle particelle assegnate.', 403);
            }
            $owner = self::owner($actor, $ownerId, 'Seleziona un proprietario esistente e accessibile.');
            $right = self::text($input['right'] ?? '', 255);
            $fraction = (string) preg_replace('/\s/u', '', self::text($input['fraction'] ?? '', 20));
            $source = self::text($input['source'] ?? '', 255);
            $note = self::text($input['note'] ?? '', 4000);
            $effectiveDate = self::text($input['effectiveDate'] ?? '', 10);
            $holding = ['right' => $right, 'fraction' => $fraction, 'rawText' => $note];
            if (! Holding::valid($holding)) {
                throw new CommandRejected('Indica un diritto catastale valido e una quota, per esempio Proprietà · 1/2.', 400, 'fraction');
            }
            if ($source === '') {
                throw new CommandRejected('Indica la fonte dell’associazione.', 400, 'source');
            }
            if ($effectiveDate !== '' && (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveDate) || ! checkdate((int) substr($effectiveDate, 5, 2), (int) substr($effectiveDate, 8, 2), (int) substr($effectiveDate, 0, 4)))) {
                throw new CommandRejected('Controlla la data della variazione.', 400, 'effectiveDate');
            }

            $unit = CadastralUnit::query()->findOrFail($unitId);
            $existing = Ownership::query()->where('agency_id', $actor->agency_id)->where('cadastral_unit_id', $unitId)
                ->where('contact_id', $ownerId)->whereNull('valid_to')->lockForUpdate()->first();
            if ($existing) {
                $old = $existing->details['holding'] ?? Holding::extract((string) $existing->right_type, $existing->share_denominator ? $existing->share_numerator.'/'.$existing->share_denominator : '');
                if (Holding::same($old, $holding)) {
                    return $existing;
                }
                throw new CommandRejected('Questo proprietario è già associato con diritti differenti. Usa “Importa o aggiorna proprietari” per registrare la nuova situazione con lo storico.');
            }

            [$numerator, $denominator] = Holding::share($holding);
            $ownership = Ownership::query()->create([
                'agency_id' => $actor->agency_id,
                'cadastral_unit_id' => $unitId,
                'parcel_id' => $unit->parcel_id,
                'contact_id' => $ownerId,
                'right_type' => $right,
                'share_numerator' => $numerator,
                'share_denominator' => $denominator,
                'valid_from' => $effectiveDate ?: now()->toDateString(),
                'source' => $source,
                'details' => ['holding' => $holding, 'verified_at' => now()->toIso8601String(), 'source' => $source, 'reason' => $note,
                    'kind' => 'Associazione', 'raw_text' => $note, 'exact_date_unknown' => $effectiveDate === '', 'actor_user_id' => $actor->user_id],
            ]);
            $this->audit->record('census.associate', $ownership, ['unitId' => $unitId, 'ownerId' => $ownerId, 'holding' => $holding], $note);

            return $ownership;
        });
    }
}
