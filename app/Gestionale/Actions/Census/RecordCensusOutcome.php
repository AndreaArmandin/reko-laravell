<?php

namespace App\Gestionale\Actions\Census;

use App\Gestionale\Actions\Activities\SaveActivity;
use App\Gestionale\Activities\ActivityCatalog;
use App\Gestionale\Census\CensusScope;
use App\Gestionale\CommandRejected;
use App\Gestionale\Idempotency;
use App\Models\Activity;
use App\Models\AgencyMembership;
use App\Models\Contact;
use App\Models\Ownership;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * census.update (census-actions.ts): riscontro del contatto con un proprietario attuale di un'unità,
 * con prossimo contatto facoltativo collegato. Avvisa se c'è già un'attività da completare oggi.
 * Idempotente tramite token (censusReceipts → idempotency_keys).
 *
 * Input: owner_id, outcome, type (Telefonata|Chiamata|…), notes, contact_operation, existing_activity_id,
 *   confirm_new, next_type, next_at, next_reason, token.
 */
final class RecordCensusOutcome extends CensusCommand
{
    public const ALIASES = ['TNT' => 'Numero non valido', 'INFO' => 'Informazione ricevuta', 'Notizia' => 'Potenziale', 'ACQ' => 'Appuntamento di acquisizione fissato'];

    public const NEXT_TYPES = ['Telefonata', 'Messaggio', 'Email', 'Appuntamento', 'Prossima attività'];

    public const UNANSWERED = ['Nessuna risposta', 'Numero non valido', 'Non è il proprietario'];

    public function __construct(private readonly SaveActivity $save, private readonly Idempotency $idempotency) {}

    public function handle(AgencyMembership $actor, int $unitId, array $input): Activity
    {
        self::gate($actor);
        $run = fn () => $this->record($actor, $unitId, $input);
        $token = self::text($input['token'] ?? '', 200);
        if ($token !== '') {
            $response = $this->idempotency->run('census.update', $token, array_diff_key($input, ['token' => true]) + ['unit' => $unitId],
                fn () => ['activity_id' => $run()->id]);

            return Activity::query()->findOrFail($response['activity_id']);
        }

        return DB::transaction($run);
    }

    /** Pending activities due today (Europe/Rome) of the actor on this owner and unit. */
    public static function pendingToday(AgencyMembership $actor, int $ownerId, int $parcelId, int $unitId): \Illuminate\Support\Collection
    {
        $today = now()->setTimezone('Europe/Rome')->toDateString();

        return Activity::query()->where('agency_id', $actor->agency_id)
            ->where('assigned_to_user_id', $actor->user_id)->where('owner_contact_id', $ownerId)->where('parcel_id', $parcelId)
            ->whereHas('units', fn ($q) => $q->where('cadastral_unit_id', $unitId))
            ->whereNotIn('status', ['Completata', 'Annullata'])->where('kind', '!=', 'Promemoria')
            ->get()->filter(fn (Activity $a) => $a->scheduled_at?->copy()->setTimezone('Europe/Rome')->toDateString() === $today)->values();
    }

    private function record(AgencyMembership $actor, int $unitId, array $d): Activity
    {
        $overlay = self::unit($actor, $unitId, true);
        $parcelId = self::parcelOf($overlay);
        $ownerId = (int) ($d['owner_id'] ?? 0);
        $current = $ownerId > 0 && Ownership::query()->where('agency_id', $actor->agency_id)->where('cadastral_unit_id', $unitId)
            ->where('contact_id', $ownerId)->whereNull('valid_to')->exists();
        $owner = $current ? Contact::query()->find($ownerId) : null;
        if (! $owner || $owner->isRemoved() || ! CensusScope::ownerVisible($actor, $ownerId)) {
            throw new CommandRejected('Scegli un proprietario attuale dell’unità.', 400, 'owner_id');
        }

        $outcome = self::text($d['outcome'] ?? '', 120);
        $outcome = self::ALIASES[$outcome] ?? $outcome;
        if (! in_array($outcome, self::outcomes(), true)) {
            throw new CommandRejected($outcome === 'NSN' ? 'NSN è ambiguo: scegli “Nessuna risposta” oppure “Numero non valido”.' : 'Scegli un esito del contatto.', 400, 'outcome');
        }
        $operation = $d['contact_operation'] ?? null;
        if ($operation !== null && $operation !== '' && (! in_array($operation, ActivityCatalog::CONTACT_OPERATIONS, true)
            || ! in_array($outcome, ActivityCatalog::outcomesForContact($operation), true))) {
            throw new CommandRejected('Scegli vendita o affitto e il relativo esito.', 400, 'contact_operation');
        }
        $operation = $operation ?: null;

        $pending = self::pendingToday($actor, $ownerId, $parcelId, $unitId);
        $existingId = (int) ($d['existing_activity_id'] ?? 0);
        if ($pending->isNotEmpty() && $existingId === 0 && ($d['confirm_new'] ?? false) !== true) {
            throw new CommandRejected('Esiste già un’attività da completare oggi per questo proprietario e immobile: selezionala oppure conferma un nuovo contatto.');
        }
        $existing = null;
        if ($existingId > 0) {
            $existing = $pending->first(fn (Activity $a) => $a->id === $existingId && in_array($a->kind, ['Telefonata', 'Prossima attività'], true))
                ?? throw new CommandRejected('L’attività selezionata non è più disponibile.');
        }

        $type = $existing?->kind ?? (($d['type'] ?? 'Telefonata') === 'Chiamata' ? 'Telefonata' : (string) ($d['type'] ?? 'Telefonata'));
        $answered = ! in_array($outcome, [...self::UNANSWERED, ...ActivityCatalog::UNANSWERED_OUTCOMES], true);
        $input = ['type' => $type, 'title' => mb_substr($outcome.' · '.$owner->display_name, 0, 180), 'notes' => self::text($d['notes'] ?? ''),
            'owner_contact_id' => $ownerId, 'parcel_id' => $parcelId, 'unit_ids' => [$unitId], 'assigned_to_user_id' => $actor->user_id,
            'done' => true, 'outcome' => $outcome, 'answered' => $answered, 'contact_operation' => $operation];
        if ($existing) {
            $input['expected_revision'] = \App\Gestionale\Commands::revision($existing);
        }
        $activity = $this->save->handle($actor, $existing, $input);

        $nextType = (string) ($d['next_type'] ?? '');
        if ($nextType !== '' && $nextType !== 'Nessuno') {
            $nextAt = null;
            try {
                $nextAt = ($d['next_at'] ?? '') !== '' ? Carbon::parse((string) $d['next_at'], 'Europe/Rome') : null;
            } catch (\Throwable) {
            }
            if (! in_array($nextType, self::NEXT_TYPES, true) || $nextAt === null || $nextAt->lte(now())) {
                throw new CommandRejected('Indica tipo e data futura del prossimo contatto.', 400, 'next_at');
            }
            $reason = trim((string) ($d['next_reason'] ?? ''));
            if ($nextType === 'Prossima attività' && ($reason === '' || mb_strlen($reason) > 4000)) {
                throw new CommandRejected('Descrivi il motivo del prossimo impegno, entro 4.000 caratteri.', 400, 'next_reason');
            }
            $next = $this->save->handle($actor, null, ['type' => $nextType, 'contact_operation' => $operation,
                'title' => mb_substr($reason, 0, 160) ?: 'Prossimo contatto · '.$owner->display_name, 'notes' => $reason, 'due_at' => $nextAt,
                'owner_contact_id' => $ownerId, 'parcel_id' => $parcelId, 'unit_ids' => [$unitId], 'done' => false]);
            $next->forceFill(['previous_activity_id' => $activity->id])->save();
            $activity->forceFill(['next_activity_id' => $next->id])->save();
        }

        return $activity->refresh();
    }
}
