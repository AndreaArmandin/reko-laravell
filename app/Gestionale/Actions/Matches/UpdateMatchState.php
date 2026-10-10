<?php

namespace App\Gestionale\Actions\Matches;

use App\Events\Gestionale\MatchNegotiationStarted;
use App\Gestionale\Actions\Activities\CreateActivity;
use App\Gestionale\Audit;
use App\Gestionale\CommandRejected;
use App\Gestionale\Commands;
use App\Models\Activity;
use App\Models\AgencyMembership;
use App\Models\Property;
use App\Models\PropertyMatch;
use App\Models\PropertyRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * match.state of the gestionale (engine.ts:277-299, "Valuta l’abbinamento" in matches.tsx):
 * - the match needs a request of the actor (crm: its own) and a property it can manage (crm: its own);
 * - 13 states; a proposal needs the preview confirmed; proposals and visits only for an Attivo or
 *   In trattativa property; a refusal or exclusion needs the reason; a visit needs a future date;
 * - a changed state leaves one activity: "Proposta di immobile" (done) and request "Immobili proposti",
 *   "Visita" (new, or the pending one moved) and request "Visita programmata", otherwise a done
 *   "Riscontro"; "In trattativa" sets the request to "In trattativa", "Concluso" to "Conclusa".
 * Feedback, reason, note, next action and visit date are the columns of property_matches.
 */
final class UpdateMatchState
{
    public const PROPOSAL_NOTE = 'Registrata nella demo. Nessuna comunicazione inviata.';

    public function __construct(private readonly Audit $audit, private readonly CreateActivity $activities) {}

    /**
     * @param  array<string, mixed>  $input  state, note, feedback, rejection_reason, next_action, visit_at, confirmed
     */
    public function handle(AgencyMembership $actor, PropertyMatch $match, array $input): PropertyMatch
    {
        Commands::gate($actor);

        return DB::transaction(function () use ($actor, $match, $input) {
            $match = PropertyMatch::query()->whereKey($match->getKey())->lockForUpdate()->firstOrFail();
            $request = Commands::requireRequest($actor, PropertyRequest::query()->find($match->property_request_id));
            $property = Property::query()->find($match->property_id);
            if ($property === null || ! ($actor->isAdmin() || ((int) $property->agent_user_id === (int) $actor->user_id && (int) $property->agency_id === (int) $actor->agency_id))) {
                throw new CommandRejected(Commands::RECORD_DENIED, 403);
            }

            $next = (string) ($input['state'] ?? '');
            if (! in_array($next, PropertyMatch::STATUSES, true)) {
                throw new CommandRejected('Stato abbinamento non valido.', 400, 'state');
            }
            if ($next === 'Proposto al cliente' && ($input['confirmed'] ?? false) !== true) {
                throw new CommandRejected('Controlla il contenuto e conferma la registrazione della proposta.', 400, 'state');
            }
            if (in_array($next, ['Proposto al cliente', 'Visita programmata'], true) && ! in_array($property->status, ['Attivo', 'In trattativa'], true)) {
                throw new CommandRejected('Immobile '.mb_strtolower($property->status).': non è disponibile per nuove proposte o visite.', 400, 'state');
            }
            $reason = Commands::text($input['rejection_reason'] ?? $match->rejection_reason);
            if (in_array($next, ['Rifiutato', 'Non compatibile'], true) && Commands::text($input['rejection_reason'] ?? '') === '') {
                throw new CommandRejected('Indica il motivo del rifiuto o dell’esclusione.', 400, 'rejection_reason');
            }

            $visitAt = self::parseDate($input['visit_at'] ?? null);
            if ($next === 'Visita programmata' && ($visitAt === null || $visitAt->lt(now()))) {
                throw new CommandRejected('Scegli una data futura per la visita.', 400, 'visit_at');
            }

            $before = $match->only(['status', 'feedback', 'rejection_reason', 'note', 'next_action', 'visit_at']);
            $changed = $match->status !== $next || ($visitAt !== null && ($match->visit_at === null || ! $visitAt->equalTo($match->visit_at)));

            $match->status = $next;
            $match->feedback = Commands::text($input['feedback'] ?? $match->feedback) ?: null;
            $match->rejection_reason = $reason ?: null;
            $match->note = Commands::text($input['note'] ?? $match->note) ?: null;
            $match->next_action = Commands::text($input['next_action'] ?? $match->next_action) ?: null;
            if ($visitAt !== null) {
                $match->visit_at = $visitAt;
            }
            $match->updated_at = now();
            $match->save();

            $links = ['contact_id' => $request->contact_id, 'property_request_id' => $request->id, 'property_id' => $property->id];
            $requestStatus = null;

            if ($changed && $next === 'Proposto al cliente') {
                $this->activities->handle($actor, [...$links, 'type' => 'Proposta di immobile', 'title' => 'Proposta: '.$property->title,
                    'notes' => $match->note.PHP_EOL.self::PROPOSAL_NOTE, 'done' => true, 'outcome' => $next]);
                $requestStatus = 'Immobili proposti';
            }
            if ($changed && $next === 'Visita programmata') {
                $previous = Activity::query()->where('kind', 'Visita')->where('property_request_id', $request->id)->where('property_id', $property->id)
                    ->where('status', '<>', 'Completata')->orderBy('id')->first();
                if ($previous !== null) {
                    $previous->forceFill(['scheduled_at' => $match->visit_at, 'updated_at' => now()])->save();
                    $visit = $previous;
                } else {
                    $visit = $this->activities->handle($actor, [...$links, 'type' => 'Visita', 'title' => 'Visita: '.$property->title,
                        'due_at' => $match->visit_at, 'notes' => (string) $match->note]);
                }
                $metadata = (array) $visit->metadata;
                if (empty($metadata['confirmed_at'])) {
                    $visit->forceFill(['metadata' => (object) [...$metadata, 'confirmed_at' => now()->toIso8601String()]])->save();
                }
                $requestStatus = 'Visita programmata';
            }
            if ($changed && ! in_array($next, ['Proposto al cliente', 'Visita programmata'], true)) {
                $this->activities->handle($actor, [...$links, 'type' => 'Riscontro', 'title' => $next.': '.$property->title, 'done' => true, 'outcome' => $next,
                    'notes' => implode(' · ', array_filter([$match->feedback, $match->rejection_reason]))]);
            }
            if ($next === 'In trattativa') {
                $requestStatus = 'In trattativa';
            }
            if ($next === 'Concluso') {
                $requestStatus = 'Conclusa';
            }
            if ($requestStatus !== null && $request->status !== $requestStatus) {
                // The request is a new revision for whoever has it open.
                $request->forceFill(['status' => $requestStatus, 'updated_at' => now()])->save();
            }

            $this->audit->record('match.state', $match, ['request_id' => $request->id, 'property_id' => $property->id,
                'before' => $before, 'after' => $match->only(array_keys($before))]);
            if ($next === 'In trattativa') {
                MatchNegotiationStarted::dispatch($match->id);
            }

            return $match;
        });
    }

    private static function parseDate(mixed $value): ?Carbon
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return Carbon::parse(trim($value));
        } catch (\Throwable) {
            return null;
        }
    }
}
