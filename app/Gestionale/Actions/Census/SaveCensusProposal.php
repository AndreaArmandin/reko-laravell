<?php

namespace App\Gestionale\Actions\Census;

use App\Gestionale\Audit;
use App\Gestionale\Census\CensusScope;
use App\Gestionale\CommandRejected;
use App\Models\AgencyMembership;
use App\Models\CensusProposal;
use Illuminate\Support\Facades\DB;

/**
 * census.propose: record a correction request for an administrator to review.
 * Proposals never modify cadastral ownership or identity data by themselves.
 */
final class SaveCensusProposal extends CensusCommand
{
    public function __construct(private readonly Audit $audit) {}

    /** @param array{note?:mixed,name?:mixed,pid?:mixed,newOwner?:mixed} $input */
    public function handle(AgencyMembership $actor, ?int $unitId, ?int $ownerId, array $input): CensusProposal
    {
        self::gate($actor);
        if ($actor->role !== 'admin' && $actor->role !== 'scout') {
            throw new CommandRejected('Collega una scheda autorizzata.', 403);
        }
        if ($unitId === null && $ownerId === null) {
            throw new CommandRejected('Collega una scheda accessibile e descrivi la proposta.', 403);
        }

        return DB::transaction(function () use ($actor, $unitId, $ownerId, $input): CensusProposal {
            $parcelId = null;
            if ($unitId !== null) {
                $unit = self::unit($actor, $unitId);
                $parcelId = self::parcelOf($unit);
            }
            if ($ownerId !== null) {
                self::owner($actor, $ownerId);
            }

            if (array_diff(array_keys($input), ['note', 'name', 'pid', 'newOwner']) !== [] || (isset($input['newOwner']) && ! is_bool($input['newOwner']))) {
                throw new CommandRejected('Dati della proposta non validi.');
            }
            $note = self::text($input['note'] ?? '', 4000);
            if ($note === '') {
                throw new CommandRejected('Descrivi i dati da verificare.', 400, 'note');
            }
            $name = self::text($input['name'] ?? '', 255);
            $pid = strtoupper(preg_replace('/[^A-Z0-9]/', '', self::text($input['pid'] ?? '', 32)) ?? '');
            if (($input['newOwner'] ?? false) === true && $name === '') {
                throw new CommandRejected('Indica il nominativo o la denominazione del nuovo proprietario.', 400, 'name');
            }

            $proposal = CensusProposal::query()->create([
                'agency_id' => $actor->agency_id,
                'cadastral_unit_id' => $unitId,
                'parcel_id' => $parcelId,
                'proposed_by_user_id' => $actor->user_id,
                'status' => 'Da verificare',
                'payload' => array_filter(['name' => $name, 'pid' => $pid, 'owner_id' => $ownerId], fn ($value) => $value !== '' && $value !== null),
                'notes' => $note,
            ]);
            $this->audit->record('census.propose', $proposal, [
                'unitId' => $unitId,
                'ownerId' => $ownerId,
                'payload' => $proposal->payload,
                'notes' => $note,
            ]);

            return $proposal;
        });
    }
}
