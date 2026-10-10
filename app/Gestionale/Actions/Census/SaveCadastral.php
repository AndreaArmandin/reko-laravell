<?php

namespace App\Gestionale\Actions\Census;

use App\Gestionale\Audit;
use App\Gestionale\Census\Holding;
use App\Gestionale\CommandRejected;
use App\Gestionale\Commands;
use App\Models\AgencyMembership;
use App\Models\AgencyUnitObservation;
use App\Models\Ownership;
use Illuminate\Support\Facades\DB;

/**
 * archive.save ("Correggi dati" della scheda unità, solo Responsabile): correzione dei dati catastali e della
 * titolarità di una unità, con il motivo. Comune, foglio, particella e subalterno non cambiano; più diritti
 * sulla stessa unità si variano solo con "Aggiorna proprietari" (conservano lo storico).
 */
final class SaveCadastral extends CensusCommand
{
    public function __construct(private readonly Audit $audit) {}

    /**
     * @param  array<string,mixed>  $input  address, floor, category, censusZone, cadastralClass, consistency, income, lastVerified,
     *                                      owners {contactId: {right, fraction, rawText, verifiedAt, livesThere}}, reason, expected_updated_at
     */
    public function handle(AgencyMembership $actor, int $unitId, array $input): AgencyUnitObservation
    {
        if (! $actor->isActive() || $actor->role === 'scout') {
            throw new CommandRejected(Commands::SCOUT_DENIED, 403);
        }
        self::gate($actor);
        self::admin($actor, self::ADMIN_ONLY_ENGINE);
        if (self::text($input['reason'] ?? '') === '') {
            throw new CommandRejected('Indica il motivo della correzione catastale.', 400, 'reason');
        }

        return DB::transaction(function () use ($actor, $unitId, $input) {
            $unit = AgencyUnitObservation::query()->where('cadastral_unit_id', $unitId)->lockForUpdate()->first() ?? throw new CommandRejected('Particella non accessibile.', 403);
            Commands::assertRevision($unit, $input['expected_updated_at'] ?? null);
            $category = self::text($input['category'] ?? '', 20);
            if (! preg_match('/^[A-Fa-f]\/[0-9]{1,2}$/', $category)) {
                throw new CommandRejected('Categoria catastale non valida: usa il formato A/2.', 400, 'category');
            }
            $income = $input['income'] ?? null;
            if ($income !== null && $income !== '' && (! is_numeric($income) || (float) $income < 0)) {
                throw new CommandRejected('La rendita catastale deve essere un numero non negativo.', 400, 'income');
            }
            $verified = self::text($input['lastVerified'] ?? '');
            if ($verified !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $verified)) {
                throw new CommandRejected('Controlla la data di ultima verifica.', 400, 'lastVerified');
            }
            $before = $unit->only(['address', 'floor', 'category', 'census_zone', 'cadastral_class', 'consistency', 'income', 'last_verified']);
            $unit->forceFill([
                'category' => strtoupper($category), 'address' => self::text($input['address'] ?? '', 1000) ?: null, 'floor' => self::text($input['floor'] ?? '', 100) ?: null,
                'census_zone' => self::text($input['censusZone'] ?? '', 40) ?: null, 'cadastral_class' => self::text($input['cadastralClass'] ?? '', 40) ?: null,
                'consistency' => self::text($input['consistency'] ?? '', 60) ?: null, 'income' => $income === null || $income === '' ? null : (float) $income,
                'last_verified' => $verified ?: null,
            ]);
            $unit->updated_at = now();
            $unit->save();

            $holdings = [];
            foreach ((array) ($input['owners'] ?? []) as $contactId => $h) {
                $ownership = Ownership::query()->where('contact_id', (int) $contactId)->where('cadastral_unit_id', $unitId)->whereNull('valid_to')->lockForUpdate()->first();
                if (! $ownership) {
                    throw new CommandRejected('Controlla i proprietari e i relativi subalterni.');
                }
                $details = $ownership->details ?? [];
                $old = $details['holding'] ?? ['right' => (string) $ownership->right_type, 'fraction' => '', 'rawText' => ''];
                if (count(Holding::components($old)) > 1) {
                    continue;
                }
                $right = self::text($h['right'] ?? '', 255);
                $fraction = preg_replace('/\s/', '', self::text($h['fraction'] ?? '', 20));
                if ($fraction !== '' && ! preg_match('/^[0-9]+\/[0-9]+$/', $fraction)) {
                    throw new CommandRejected('Controlla la quota: usa il formato 1/2.', 400, 'fraction');
                }
                $holding = ['right' => $right, 'fraction' => $fraction, 'rawText' => self::text($h['rawText'] ?? '', 4000)];
                $details['holding'] = $holding;
                $details['verified_at'] = self::text($h['verifiedAt'] ?? '') ?: null;
                $details['lives_there'] = ($h['livesThere'] ?? '') === '' ? null : in_array($h['livesThere'], ['si', true, 'true', '1'], true);
                [$n, $d] = Holding::share($holding);
                $ownership->forceFill(['details' => $details, 'right_type' => $right ?: null, 'share_numerator' => $n, 'share_denominator' => $d])->save();
                $holdings[(int) $contactId] = $holding;
            }
            $this->audit->record('archive.save', $unit, ['before' => $before, 'after' => $unit->only(array_keys($before)), 'holdings' => $holdings], self::text($input['reason'] ?? ''));

            return $unit;
        });
    }
}
