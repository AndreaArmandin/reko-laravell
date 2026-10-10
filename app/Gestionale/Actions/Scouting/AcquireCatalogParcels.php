<?php

namespace App\Gestionale\Actions\Scouting;

use App\Gestionale\Audit;
use App\Gestionale\CommandRejected;
use App\Gestionale\Census\CensusScope;
use App\Gestionale\Idempotency;
use App\Models\AgencyMembership;
use App\Models\AgencyUnitObservation;
use App\Models\CensusBatch;
use App\Models\CadastralUnit;
use App\Trova\CatalogSearch;
use App\Trova\Presentation;
use Illuminate\Support\Facades\DB;

/** Adds eligible units from the active REKO catalogue to an agency census, without importing owners. */
final class AcquireCatalogParcels
{
    public function __construct(private readonly Audit $audit, private readonly Idempotency $idempotency) {}

    /** @param array{parcels?: mixed, token?: mixed} $input */
    public function handle(AgencyMembership $actor, array $input): array
    {
        $parcels = self::validate($actor, $input['parcels'] ?? null);
        $token = trim((string) ($input['token'] ?? ''));
        if ($token !== '') {
            if (mb_strlen($token) > 100) {
                throw new CommandRejected('Identificativo operazione non valido.');
            }

            return $this->idempotency->run('catalog.acquire', $token, ['parcels' => $parcels], fn () => $this->apply($actor, $parcels)) ?? [];
        }

        return DB::transaction(fn () => $this->apply($actor, $parcels));
    }

    /** Acquisitions visible to this operator, for the list below the scouting map. @return list<array<string,mixed>> */
    public static function acquired(AgencyMembership $actor): array
    {
        $query = DB::table('agency_unit_observations as o')->join('cadastral_units as cu', 'cu.id', '=', 'o.cadastral_unit_id')
            ->join('parcels as p', 'p.id', '=', 'cu.parcel_id')->join('municipalities as m', 'm.id', '=', 'p.municipality_id')
            ->where('o.agency_id', $actor->agency_id)->whereNotNull('o.catalog_key')->where('o.state', 'Attivo');
        if ($actor->role !== 'admin') {
            $query->whereIn('o.cadastral_unit_id', CensusScope::units($actor)->select('o.cadastral_unit_id'));
        }

        return $query->orderBy('m.name')->orderBy('p.sheet')->orderBy('p.number')->orderBy('cu.subalterno')
            ->limit(100)->get(['m.name as municipality', 'p.section', 'p.sheet', 'p.number', 'cu.subalterno', 'o.address', 'o.category', 'o.consistency', 'o.catalog_key'])
            ->map(fn ($row) => (array) $row)->all();
    }

    /** @return list<array{code:string,section:string,sheet:string,parcel:string}> */
    private static function validate(AgencyMembership $actor, mixed $input): array
    {
        if (! $actor->isActive() || ! in_array($actor->role, ['admin', 'scout'], true)) {
            throw new CommandRejected('Sezione non accessibile con questo ruolo.', 403);
        }
        if ($actor->role !== 'admin' && ! (($actor->catalog_package['enabled'] ?? false) === true)) {
            throw new CommandRejected('Acquisizione del catalogo non abilitata per il tuo pacchetto.', 403);
        }
        if (! is_array($input) || $input === [] || count($input) > 25) {
            throw new CommandRejected('Seleziona da 1 a 25 particelle per acquisizione.');
        }

        $picks = [];
        foreach ($input as $pick) {
            if (! is_array($pick)) {
                throw new CommandRejected('Seleziona da 1 a 25 particelle per acquisizione.');
            }
            $code = strtoupper(trim((string) ($pick['code'] ?? '')));
            $section = strtoupper(trim((string) ($pick['section'] ?? '')));
            $sheet = strtoupper(trim((string) ($pick['sheet'] ?? '')));
            $parcel = strtoupper(trim((string) ($pick['parcel'] ?? '')));
            if (! preg_match('/^[A-Z][0-9]{3}$/', $code) || ! preg_match('/^[A-Z0-9_]{0,12}$/', $section)
                || ! preg_match('/^\d+[A-Z]?$/', $sheet) || ! preg_match('/^[A-Z0-9]+$/', $parcel)) {
                throw new CommandRejected('Seleziona da 1 a 25 particelle per acquisizione.');
            }
            $picks[] = ['code' => $code, 'section' => $section === '_' ? '' : $section,
                'sheet' => CatalogSearch::cadastralId($sheet), 'parcel' => CatalogSearch::cadastralId($parcel)];
        }

        if (count(array_unique(array_column($picks, 'code'))) !== 1) {
            throw new CommandRejected('Seleziona particelle dello stesso Comune per ogni acquisizione.');
        }
        $picks = collect($picks)->unique(fn ($p) => implode('|', $p))->values()->all();
        if ($picks === []) {
            throw new CommandRejected('Seleziona da 1 a 25 particelle per acquisizione.');
        }

        return $picks;
    }

    /** @param list<array{code:string,section:string,sheet:string,parcel:string}> $picks */
    private function apply(AgencyMembership $actor, array $picks): array
    {
        $code = $picks[0]['code'];
        $norm = fn (string $column) => "CASE WHEN {$column} ~ '^[0-9]+$' THEN regexp_replace({$column}, '^0+(?=[0-9])', '') ELSE upper(btrim({$column})) END";
        $records = [];
        foreach ($picks as $pick) {
            $rows = DB::table('parcels as p')
                ->join('municipalities as m', 'm.id', '=', 'p.municipality_id')
                ->join('municipality_catalogs as mc', 'mc.municipality_id', '=', 'm.id')
                ->join('catalog_releases as cr', 'cr.id', '=', 'mc.catalog_release_id')
                ->join('cadastral_units as cu', 'cu.parcel_id', '=', 'p.id')
                ->join('cadastral_unit_versions as v', function ($join) {
                    $join->on('v.cadastral_unit_id', '=', 'cu.id')->on('v.catalog_release_id', '=', 'mc.catalog_release_id');
                })
                ->where('m.cadastral_code', $code)->where('p.cadastral_kind', 'F')
                ->whereRaw('upper(btrim(p.section)) = ?', [$pick['section']])
                ->whereRaw($norm('p.sheet').' = ?', [$pick['sheet']])
                ->whereRaw($norm('p.number').' = ?', [$pick['parcel']])
                ->where('v.status', 'eligible')->whereNotNull('v.category')->whereRaw("btrim(v.category) <> ''")
                ->orderBy('cu.id')->limit(5001 - count($records))
                ->get(['p.id as parcel_id', 'p.section', 'p.sheet', 'p.number', 'm.name as municipality', 'm.cadastral_code as code',
                    'mc.catalog_release_id', 'cr.released_on', 'cu.id as unit_id', 'cu.subalterno', 'cu.legacy_key', 'cu.source_ref',
                    'v.category', 'v.class as cadastral_class', 'v.consistency', 'v.consistency_unit', 'v.rendita', 'v.address_raw',
                    'v.floor', 'v.census_zone', 'v.partita']);
            foreach ($rows as $row) {
                $row->catalog_key = $row->legacy_key ?: self::fallbackCatalogKey($row);
                $records[] = $row;
            }
            if (count($records) > 5000) {
                throw new CommandRejected('Oltre 5.000 righe: acquisisci meno particelle alla volta.');
            }
        }

        if ($records === []) {
            throw new CommandRejected('Non sono ancora disponibili righe per le particelle selezionate.');
        }
        $byPick = collect($records)->map(fn ($r) => implode('|', [$r->code, strtoupper($r->section), CatalogSearch::cadastralId($r->sheet), CatalogSearch::cadastralId($r->number)]))->unique();
        foreach ($picks as $pick) {
            if (! $byPick->contains(implode('|', [$pick['code'], $pick['section'], $pick['sheet'], $pick['parcel']]))) {
                throw new CommandRejected('Non sono ancora disponibili righe per una delle particelle selezionate.');
            }
        }
        if (collect($records)->pluck('catalog_release_id')->unique()->count() !== 1) {
            throw new CommandRejected('Le particelle selezionate non appartengono alla stessa edizione del catalogo.');
        }
        $records = collect($records)->unique('catalog_key')->values();
        if ($records->count() > 5000) {
            throw new CommandRejected('Oltre 5.000 righe: acquisisci meno particelle alla volta.');
        }

        $parcelIds = $records->pluck('parcel_id')->unique()->map(fn ($id) => (int) $id)->all();
        $unitIds = $records->pluck('unit_id')->map(fn ($id) => (int) $id)->all();
        $assignments = DB::table('scouting_assignments')->where('agency_id', $actor->agency_id)
            ->where(fn ($q) => $q->whereIn('parcel_id', $parcelIds)->orWhereIn('cadastral_unit_id', $unitIds))->get(['user_id', 'parcel_id', 'cadastral_unit_id']);
        if ($actor->role !== 'admin') {
            $foreignAssignments = $assignments->contains(fn ($a) => (int) $a->user_id !== (int) $actor->user_id);
            $ownedParcels = DB::table('agency_unit_observations as o')->join('cadastral_units as cu', 'cu.id', '=', 'o.cadastral_unit_id')
                ->where('o.agency_id', $actor->agency_id)->whereIn('cu.parcel_id', $parcelIds)->distinct()->pluck('cu.parcel_id')->map(fn ($id) => (int) $id)->all();
            $assignedToActor = $assignments->contains(fn ($a) => (int) $a->user_id === (int) $actor->user_id);
            if ($foreignAssignments || ($ownedParcels !== [] && ! $assignedToActor)) {
                throw new CommandRejected('Una particella selezionata è già censita fuori dalla tua area di accesso. Chiedi l’assegnazione all’amministratore.', 403);
            }

            $already = DB::table('agency_unit_observations as o')->join('cadastral_units as cu', 'cu.id', '=', 'o.cadastral_unit_id')
                ->where('o.agency_id', $actor->agency_id)->whereNotNull('o.catalog_key')
                ->whereExists(fn ($q) => $q->selectRaw('1')->from('scouting_assignments as sa')->whereColumn('sa.agency_id', 'o.agency_id')
                    ->where('sa.user_id', $actor->user_id)->where(fn ($w) => $w->whereColumn('sa.parcel_id', 'cu.parcel_id')->orWhereColumn('sa.cadastral_unit_id', 'cu.id')))
                ->distinct()->count('cu.parcel_id');
            $additionIds = DB::table('agency_unit_observations as o')->join('cadastral_units as cu', 'cu.id', '=', 'o.cadastral_unit_id')
                ->where('o.agency_id', $actor->agency_id)->whereNotNull('o.catalog_key')->whereIn('cu.parcel_id', $parcelIds)->distinct()->pluck('cu.parcel_id')->map(fn ($id) => (int) $id)->all();
            $additions = count(array_diff($parcelIds, $additionIds));
            $package = $actor->catalog_package ?? [];
            if (($package['unlimited'] ?? false) !== true && $already + $additions > (int) ($package['maxParcels'] ?? 0)) {
                throw new CommandRejected('Il limite di particelle del pacchetto non consente questa acquisizione.', 403);
            }
        }

        $fingerprint = hash('sha256', json_encode([$actor->user_id, $records->pluck('catalog_key')->sort()->values()->all()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        if ($batch = CensusBatch::query()->where('fingerprint', $fingerprint)->first()) {
            return ['id' => $batch->id, 'unchanged' => true, 'units' => (int) ($batch->summary['units'] ?? 0), 'parcels' => count($parcelIds)];
        }

        $overlays = [];
        $newUnits = 0;
        foreach ($records as $record) {
            /** @var AgencyUnitObservation|null $overlay */
            $overlay = AgencyUnitObservation::query()->where('cadastral_unit_id', $record->unit_id)->lockForUpdate()->first();
            if ($overlay !== null) {
                if ($overlay->source === 'demo') {
                    throw new CommandRejected('Questo riferimento appartiene a una scheda dimostrativa. Verifica separatamente la fonte prima di collegare il catalogo.');
                }
                if ($overlay->catalog_key !== null && $overlay->catalog_key !== $record->catalog_key) {
                    throw new CommandRejected('L’unità è già collegata a un riferimento di catalogo differente. Verifica la fonte prima di acquisirla.');
                }
                if ($overlay->state !== 'Attivo') {
                    throw new CommandRejected('Un’unità selezionata risulta soppressa o eliminata nel censimento. Verifica la fonte prima di acquisirla.');
                }
                $overlay->forceFill(['catalog_key' => $record->catalog_key]);
            } else {
                $newUnits++;
                $details = json_decode((string) $record->catalog_key, true);
                $overlay = new AgencyUnitObservation;
                $overlay->forceFill([
                    'agency_id' => $actor->agency_id, 'cadastral_unit_id' => $record->unit_id, 'user_id' => $actor->user_id,
                    'source' => 'catalog', 'state' => 'Attivo', 'category' => $record->category,
                    'cadastral_class' => $record->cadastral_class, 'census_zone' => $record->census_zone,
                    'consistency' => $record->consistency === null ? null : Presentation::measure($record->consistency, $record->consistency_unit),
                    'consistency_value' => $record->consistency, 'consistency_unit' => $record->consistency_unit,
                    'income' => $record->rendita, 'batch' => $record->partita, 'address' => $record->address_raw,
                    'raw_address' => $record->address_raw, 'floor' => $record->floor, 'raw_classing' => $record->category,
                    'catalog_key' => $record->catalog_key, 'identity_detail' => is_array($details) ? ($details[6] ?? null) : null,
                    'last_verified' => $record->released_on, 'observed_on' => $record->released_on,
                    'data' => ['catalogReleaseId' => (int) $record->catalog_release_id],
                ]);
            }
            $overlays[] = $overlay;
        }

        if ($actor->role === 'scout') {
            foreach ($parcelIds as $parcelId) {
                if (! $assignments->contains(fn ($a) => (int) $a->parcel_id === $parcelId && $a->cadastral_unit_id === null)) {
                    CensusScope::assignParcel((int) $actor->agency_id, $parcelId, (int) $actor->user_id);
                }
            }
        }

        foreach ($overlays as $overlay) {
            $overlay->save();
        }

        $release = $records->first()->released_on;
        $batch = CensusBatch::query()->create([
            'actor_user_id' => $actor->user_id, 'source' => 'Catalogo REKO', 'source_date' => $release,
            'fingerprint' => $fingerprint,
            'summary' => ['units' => $records->count(), 'parcels' => count($parcelIds), 'newUnits' => $newUnits,
                'linkedUnits' => $records->count() - $newUnits, 'catalogReleaseId' => (int) $records->first()->catalog_release_id],
            'issues' => [],
        ]);
        AgencyUnitObservation::query()->whereIn('cadastral_unit_id', $records->pluck('unit_id'))->update(['census_batch_id' => $batch->id]);
        $this->audit->record('catalog.acquire', $batch, ['units' => $records->count(), 'parcels' => count($parcelIds), 'catalogReleaseId' => (int) $records->first()->catalog_release_id]);

        return ['id' => $batch->id, 'unchanged' => false, 'units' => $records->count(), 'parcels' => count($parcelIds)];
    }

    private static function fallbackCatalogKey(object $record): string
    {
        $key = [$record->code, 'Fabbricati', (string) $record->section, CatalogSearch::cadastralId((string) $record->sheet),
            CatalogSearch::cadastralId((string) $record->number), CatalogSearch::cadastralId((string) $record->subalterno ?? '')];
        if (($record->subalterno ?? null) === null) {
            $key[] = json_encode(array_map(fn ($v) => mb_strtoupper(trim((string) ($v ?? ''))), [
                $record->address_raw, $record->census_zone, $record->category, $record->cadastral_class,
                $record->consistency === null ? '' : $record->consistency.' '.$record->consistency_unit,
                $record->rendita, $record->partita,
            ]), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        return json_encode($key, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
