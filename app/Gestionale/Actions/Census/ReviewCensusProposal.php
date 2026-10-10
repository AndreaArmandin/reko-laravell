<?php

namespace App\Gestionale\Actions\Census;

use App\Gestionale\Audit;
use App\Gestionale\CommandRejected;
use App\Models\AgencyMembership;
use App\Models\CensusProposal;
use Illuminate\Support\Facades\DB;

/** Admin review of an unstructured proposal. Approval records verification only. */
final class ReviewCensusProposal extends CensusCommand
{
    public function __construct(private readonly Audit $audit) {}

    public function handle(AgencyMembership $actor, int $proposalId, array $input): CensusProposal
    {
        self::gate($actor);
        self::admin($actor, 'La revisione delle proposte è riservata all’Amministratore.');

        return DB::transaction(function () use ($actor, $proposalId, $input): CensusProposal {
            $proposal = CensusProposal::query()->where('agency_id', $actor->agency_id)->whereKey($proposalId)->lockForUpdate()->first();
            if (! $proposal) {
                throw new CommandRejected('Proposta non trovata.', 404);
            }
            if (array_diff(array_keys($input), ['decision', 'reason']) !== []) {
                throw new CommandRejected('Dati della revisione non validi.');
            }
            $decision = $input['decision'] ?? null;
            if (! in_array($decision, ['approve', 'reject'], true)) {
                throw new CommandRejected('Scegli Approva oppure Rifiuta.');
            }
            $reason = self::text($input['reason'] ?? '', 4000);
            if ($decision === 'reject' && $reason === '') {
                throw new CommandRejected('Indica il motivo del rifiuto, entro 4.000 caratteri.', 400, 'reason');
            }
            $status = $decision === 'approve' ? 'Approvata' : 'Rifiutata';
            if ($proposal->status === $status && ($proposal->review['reason'] ?? '') === $reason) {
                return $proposal;
            }
            if ($proposal->status !== 'Da verificare') {
                throw new CommandRejected('La proposta è già stata esaminata. Aggiorna la vista.', 409);
            }

            $before = ['status' => $proposal->status, 'review' => $proposal->review];
            $proposal->forceFill([
                'status' => $status,
                'review' => ['at' => now()->toIso8601String(), 'actorId' => $actor->user_id, 'reason' => $reason],
            ])->save();
            $this->audit->record('census.proposal.review', $proposal, [
                'before' => $before,
                'after' => ['status' => $proposal->status, 'review' => $proposal->review],
            ], $reason);

            return $proposal;
        });
    }
}
