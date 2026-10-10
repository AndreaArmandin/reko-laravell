<?php

namespace App\Gestionale\Actions\Census;

use App\Gestionale\Audit;
use App\Gestionale\Census\CensusReader;
use App\Gestionale\CommandRejected;
use App\Gestionale\Commands;
use App\Models\AgencyMembership;
use App\Models\AgencyUnitObservation;
use Illuminate\Support\Facades\DB;

/**
 * census.unit.location: via, civico e frazione di una unità (solo Responsabile). Il testo acquisito resta
 * conservato; non si deduce nulla dall'indirizzo SISTER.
 */
final class SaveLocation extends CensusCommand
{
    public function __construct(private readonly Audit $audit) {}

    public function handle(AgencyMembership $actor, int $unitId, array $input): AgencyUnitObservation
    {
        self::gate($actor);

        return DB::transaction(function () use ($actor, $unitId, $input) {
            $unit = self::unit($actor, $unitId, true);
            self::admin($actor);
            if (isset($input['expected_updated_at'])) {
                Commands::assertRevision($unit, $input['expected_updated_at']);
            }
            $reason = self::reason($input);
            $street = self::text($input['street'] ?? '');
            $civic = self::text($input['civic'] ?? '');
            $locality = self::text($input['locality'] ?? '');
            if ($street === '' || mb_strlen($street) > 255 || mb_strlen($civic) > 30 || mb_strlen($locality) > 120) {
                throw new CommandRejected('Indica la via; civico e frazione sono facoltativi. Controlla la lunghezza dei campi.', 400, 'street');
            }
            $before = $unit->only(['street', 'civic', 'locality', 'address']);
            $municipality = DB::table('cadastral_units as cu')->join('parcels as p', 'p.id', '=', 'cu.parcel_id')->join('municipalities as m', 'm.id', '=', 'p.municipality_id')
                ->where('cu.id', $unit->cadastral_unit_id)->first(['m.name', 'p.sheet', 'p.number']);
            $fallback = $municipality->name.' · F. '.$municipality->sheet.' P. '.$municipality->number;
            $unit->forceFill(['street' => $street, 'civic' => $civic, 'locality' => $locality, 'location_source_address' => ($unit->address ?: $fallback)]);
            $unit->updated_at = now();
            $unit->save();
            $this->audit->record('census.unit.location', $unit, ['before' => $before, 'after' => $unit->only(array_keys($before))], $reason);

            return $unit;
        });
    }
}
