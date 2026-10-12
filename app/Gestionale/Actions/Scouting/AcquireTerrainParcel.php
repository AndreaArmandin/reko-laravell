<?php

namespace App\Gestionale\Actions\Scouting;

use App\Gestionale\Audit;
use App\Gestionale\CommandRejected;
use App\Gestionale\Idempotency;
use App\Models\AgencyMembership;
use App\Models\AgencyTerrainAcquisition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Adds a verified Catasto Terreni perimeter to the agency archive without inventing units or owners. */
final class AcquireTerrainParcel
{
    public function __construct(private readonly Audit $audit, private readonly Idempotency $idempotency) {}

    /** @param array{code?:mixed,section?:mixed,sheet?:mixed,parcel?:mixed,token?:mixed} $input */
    public function handle(AgencyMembership $actor, array $input): array
    {
        if (! $actor->isActive() || $actor->role !== 'admin') {
            throw new CommandRejected('L’acquisizione dei perimetri Terreni è riservata all’Amministratore.', 403);
        }
        if (! Schema::hasTable('agency_terrain_acquisitions')) {
            throw new CommandRejected('Archivio dei perimetri non disponibile: applicare le migration del gestionale.');
        }
        $code = strtoupper(trim((string) ($input['code'] ?? '')));
        $section = strtoupper(trim((string) ($input['section'] ?? '')));
        $sheet = strtoupper(trim((string) ($input['sheet'] ?? '')));
        $parcel = strtoupper(trim((string) ($input['parcel'] ?? '')));
        $token = trim((string) ($input['token'] ?? ''));
        if (! preg_match('/^[A-Z][0-9]{3}$/', $code) || ! preg_match('/^[A-Z0-9_]{0,3}$/', $section)
            || ! preg_match('/^\d{1,4}[A-Z]?$/', $sheet) || ! preg_match('/^[A-Z0-9][A-Z0-9.\/-]{0,15}$/', $parcel) || ! preg_match('/^[A-Za-z0-9-]{1,100}$/', $token)) {
            throw new CommandRejected('Selezione della particella non valida.');
        }

        return $this->idempotency->run('terrain.acquire', $token, compact('code', 'section', 'sheet', 'parcel'), fn () => $this->apply($actor, $code, $section, $sheet, $parcel))
            ?? ['added' => 0, 'existing' => 0];
    }

    private function apply(AgencyMembership $actor, string $code, string $section, string $sheet, string $number): array
    {
        $strip = fn (string $value) => (string) preg_replace('/^0+(?=\d)/', '', $value);
        $norm = fn (string $column) => "CASE WHEN {$column} ~ '^[0-9]+$' THEN regexp_replace({$column}, '^0+(?=[0-9])', '') ELSE upper(btrim({$column})) END";
        return DB::transaction(function () use ($actor, $code, $section, $sheet, $number, $strip, $norm) {
            $parcel = DB::table('parcels as p')->join('municipalities as m', 'm.id', '=', 'p.municipality_id')
                ->join('municipality_catalogs as mc', 'mc.municipality_id', '=', 'm.id')
                ->join('parcel_versions as pv', fn ($join) => $join->on('pv.parcel_id', '=', 'p.id')->on('pv.catalog_release_id', '=', 'mc.catalog_release_id'))
                ->where('m.cadastral_code', $code)->where('p.cadastral_kind', 'T')->whereRaw('upper(btrim(p.section)) = ?', [$section === '_' ? '' : $section])
                ->whereRaw($norm('p.sheet').' = ?', [$strip($sheet)])->whereRaw($norm('p.number').' = ?', [$strip($number)])
                ->whereNotNull('pv.boundary')->select('p.id', 'mc.catalog_release_id')
                ->selectRaw('ST_Area(pv.boundary::geography) AS area_sqm')->lockForUpdate()->first();
            if ($parcel === null) {
                throw new CommandRejected('Perimetro non presente nel catalogo catastale attivo. Nessuna acquisizione effettuata.');
            }
            $existing = AgencyTerrainAcquisition::query()->where('agency_id', $actor->agency_id)->where('parcel_id', $parcel->id)->lockForUpdate()->first();
            if ($existing !== null) {
                return ['added' => 0, 'existing' => 1, 'id' => (int) $existing->id];
            }

            $acquisition = AgencyTerrainAcquisition::query()->create([
                'agency_id' => $actor->agency_id, 'parcel_id' => $parcel->id, 'catalog_release_id' => $parcel->catalog_release_id,
                'acquired_by_user_id' => $actor->user_id, 'acquired_at' => now(),
                'area_sqm' => $parcel->area_sqm === null ? null : round((float) $parcel->area_sqm, 2),
            ]);
            $this->audit->record('terrain.acquire', $acquisition, ['parcelId' => (int) $parcel->id, 'catalogReleaseId' => (int) $parcel->catalog_release_id]);

            return ['added' => 1, 'existing' => 0, 'id' => (int) $acquisition->id];
        });
    }
}
