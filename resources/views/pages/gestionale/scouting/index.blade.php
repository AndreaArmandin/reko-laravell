<?php

use App\Gestionale\Actions\Scouting\AssignParcelOperator;
use App\Gestionale\Actions\Scouting\AcquireCatalogParcels;
use App\Gestionale\Actions\Scouting\SaveCensusPlan;
use App\Gestionale\Actions\Scouting\SaveZoneBoundary;
use App\Gestionale\CommandRejected;
use App\Gestionale\Commands;
use App\Gestionale\Livewire\HandlesCommands;
use App\Gestionale\Scouting\CensusPlan;
use App\Gestionale\Scouting\MapContext;
use App\Gestionale\Scouting\ParcelPanel;
use App\Gestionale\Scouting\ScoutingMap;
use App\Gestionale\Scouting\ScoutingState;
use App\Models\AgencyMembership;
use App\Models\ScoutingZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * Mappa e zone (components/prototype/crm/scout-zones.tsx, census-plan.tsx, public-neighborhood-picker.tsx).
 * Riservata a Responsabile e Agente acquisizioni; l'agente vede solo le proprie zone e le proprie particelle.
 */
new #[Layout('layouts::gestionale'), Title('Mappa e zone')] class extends Component {
    use HandlesCommands;

    public const MAP = 'scout-map';

    public string $view = 'Mappa';

    public string $municipalityCode = 'F205';

    public ?int $publicZoneId = null;

    public bool $choosingPublicZone = false;

    public bool $cartographyOpen = false;

    public ?int $pickedPublicZone = null;

    #[Url(as: 'parcel')]
    public ?int $selected = null;

    /** Particella scelta sulla cartografia senza dati di censimento: code, section, sheet, parcel, key */
    #[Locked]
    public ?array $mapSelection = null;

    public string $street = 'Tutte';

    public string $outcome = 'Tutti';

    public int $center = 0;

    public bool $showUnverifiedBoundary = false;

    public string $planId = '';

    // Disegno dei confini (i vertici restano nel browser fino al salvataggio)
    public bool $drawing = false;

    public ?int $draftId = null;

    public string $draftName = '';

    public string $draftOperator = '';

    public array $draftPolygon = [];

    public int $draftSeed = 0;

    public string $draftRevision = '';

    public string $draftToken = '';

    public string $boundaryError = '';

    // Elenco immobili della particella
    public bool $showUnits = false;

    public int $unitsPage = 1;

    /** Particelle selezionate per l’acquisizione dal catalogo REKO. */
    public array $catalogPicks = [];

    public string $catalogToken = '';

    // Nuova Scouting Zone / Piano di censimento
    public bool $zoneFormOpen = false;

    public ?int $zoneFormId = null;

    public array $zf = ['name' => '', 'description' => '', 'operator_id' => '', 'status' => 'Pianificata', 'start' => '', 'plan' => ''];

    public string $zfRevision = '';

    public string $zfToken = '';

    public string $zfError = '';

    public function mount(): void
    {
        $this->guard();
        $this->catalogToken = (string) Str::uuid();
        $codes = array_column($this->inventories, 'code');
        $this->municipalityCode = $codes === [] || in_array('F205', $codes, true) ? 'F205' : $codes[0];
    }

    private function guard(): AgencyMembership
    {
        $membership = $this->actor();
        abort_unless($membership->isActive() && in_array($membership->role, ['admin', 'scout'], true), 403, 'Sezione non accessibile con questo ruolo.');

        return $membership;
    }

    // ---- Dati

    #[Computed]
    public function membership(): AgencyMembership
    {
        return $this->guard();
    }

    /** I Comuni con cartografia (municipalCartography). */
    #[Computed]
    public function inventories(): array
    {
        return ScoutingMap::municipalities();
    }

    #[Computed]
    public function municipality(): ?array
    {
        return collect($this->inventories)->firstWhere('code', $this->municipalityCode);
    }

    /** Operatori attivi: gli Agenti acquisizioni dell'agenzia. */
    #[Computed]
    public function scouts()
    {
        return AgencyMembership::query()->where('agency_id', $this->membership->agency_id)->where('role', 'scout')->active()->with('user')->get()
            ->sortBy(fn ($m) => mb_strtolower($m->user->name))->values();
    }

    /** Le zone visibili: tutte per il Responsabile, le proprie per l'agente. */
    #[Computed]
    public function allZones()
    {
        $membership = $this->membership;
        $query = ScoutingZone::query()->select(['id', 'agency_id', 'operator_user_id', 'name', 'notes', 'status', 'starts_on', 'municipalities', 'plan', 'created_at', 'updated_at'])
            ->selectRaw('boundary IS NOT NULL AS has_boundary')->with('assignments');
        if ($membership->role !== 'admin') {
            $query->where(fn ($q) => $q->where('operator_user_id', $membership->user_id)->orWhereHas('assignments', fn ($a) => $a->where('user_id', $membership->user_id)));
        }

        return $query->orderBy('name')->orderBy('id')->get();
    }

    #[Computed]
    public function zones()
    {
        return $this->allZones->filter(fn ($z) => in_array($this->municipalityCode, CensusPlan::zoneMunicipalities($z), true))->values();
    }

    #[Computed]
    public function area(): ?ScoutingZone
    {
        return $this->planId === '' ? null : $this->zones->firstWhere('id', (int) $this->planId);
    }

    /** Confini della zona scelta: GeoJSON, tutti i punti e l'anello se è un'unica area. */
    #[Computed]
    public function areaBoundary(): array
    {
        $none = ['geojson' => null, 'points' => [], 'ring' => []];
        if ($this->area === null || ! $this->area->has_boundary) {
            return $none;
        }
        $row = DB::selectOne('SELECT ST_AsGeoJSON(boundary, 6) AS g FROM scouting_zones WHERE agency_id = ? AND id = ?', [$this->membership->agency_id, $this->area->id]);
        $geometry = $row?->g === null ? null : json_decode($row->g, true);
        $rings = ScoutingMap::rings($geometry);
        if ($rings === []) {
            return $none;
        }
        $points = [];
        foreach ($rings as $polygon) {
            foreach ($polygon[0] ?? [] as $point) {
                $points[] = $point;
            }
        }

        return ['geojson' => $geometry, 'points' => $points, 'ring' => count($rings) === 1 ? ScoutingMap::boundaryPoints($row->g) : null];
    }

    #[Computed]
    public function canDraw(): bool
    {
        return MapContext::coverageFit($this->municipalityCode, $this->inventories) !== [] && ScoutingMap::hasCatalog($this->municipalityCode) && in_array($this->membership->role, ['admin', 'scout'], true);
    }

    #[Computed]
    public function publicZone(): ?array
    {
        if ($this->publicZoneId === null) {
            return null;
        }
        $shape = ScoutingMap::neighborhoodShapes($this->municipalityCode, $this->publicZoneId)['features'][0] ?? null;

        return $shape === null ? null : ['id' => $this->publicZoneId, 'name' => $shape['properties']['name'], 'feature' => $shape];
    }

    #[Computed]
    public function neighborhoods(): array
    {
        return ScoutingMap::neighborhoods($this->municipalityCode);
    }

    /** Particelle, filtri, selezione e dati da disegnare. */
    #[Computed]
    public function mapState(): array
    {
        $membership = $this->membership;
        $area = $this->area;
        $filters = ['street' => $this->street, 'outcome' => $this->outcome, 'publicZoneId' => $this->publicZone['id'] ?? null,
            'zoneId' => ! $this->drawing && $area !== null && $area->has_boundary ? $area->id : null];
        $result = ScoutingMap::parcels($membership, $this->municipalityCode, $filters);
        $parcels = $result['parcels'];
        $ids = array_column($parcels, 'id');
        $states = ScoutingState::forParcels((int) $membership->agency_id, $ids, $membership->role === 'admin' ? null : (int) $membership->user_id);
        $agents = ScoutingMap::parcelAgents((int) $membership->agency_id, $ids);

        $parcel = null;
        if ($this->mapSelection !== null) {
            $parcel = collect($parcels)->first(fn ($p) => ScoutingMap::outlineKey($p['code'], $p['section'], $p['sheet'], $p['parcel'], $p['kind']) === $this->mapSelection['key']);
        } elseif ($this->selected !== null) {
            $parcel = collect($parcels)->firstWhere('id', $this->selected);
        }

        $features = array_map(fn ($p) => ['type' => 'Feature', 'properties' => ['id' => $p['id'], 'label' => $p['parcel'], 'state' => $states[$p['id']] ?? 0], 'geometry' => $p['geometry']], $parcels);

        return ['parcels' => $parcels, 'features' => $features, 'addresses' => $result['addresses'], 'missing' => $result['missing'], 'states' => $states, 'agents' => $agents,
            'parcel' => $parcel, 'buildings' => ScoutingMap::buildings($parcels)];
    }

    /** Identità della particella aperta nel pannello (censimento o cartografia). */
    #[Computed]
    public function selectedIdentity(): ?array
    {
        $parcel = $this->mapState['parcel'];
        if ($this->mapSelection !== null) {
            $code = $this->mapSelection['code'];
            $section = $this->mapSelection['section'];
            $sheet = $this->mapSelection['sheet'];
            $number = $this->mapSelection['parcel'];
            $kind = $this->mapSelection['kind'];
        } elseif ($parcel !== null) {
            [$code, $section, $sheet, $number] = [$parcel['code'], $parcel['section'], $parcel['sheet'], $parcel['parcel']];
            $kind = $parcel['kind'];
        } else {
            return null;
        }
        $inventory = collect($this->inventories)->firstWhere('code', $code);

        return ['municipality' => $inventory['municipality'] ?? $code, 'code' => $code, 'kind' => $kind,
            'section' => $section === '_' ? '' : $section, 'sheet' => $sheet, 'parcel' => $number];
    }

    /** La particella del catalogo dell'identità aperta (per elenco immobili, misure, assegnazione). */
    #[Computed]
    public function selectedCatalogParcel(): ?object
    {
        $i = $this->selectedIdentity;

        return $i === null ? null : ParcelPanel::find($i['code'], $i['section'], $i['sheet'], $i['parcel'], $i['kind']);
    }

    #[Computed]
    public function canAcquireCatalog(): bool
    {
        $membership = $this->membership;

        return $membership->role === 'admin' || (($membership->catalog_package['enabled'] ?? false) === true);
    }

    #[Computed]
    public function acquiredCatalogUnits(): array
    {
        return AcquireCatalogParcels::acquired($this->membership);
    }

    /** Il bordo da mostrare per fitBoundary: [lat, lng] punti. */
    #[Computed]
    public function fitBoundary(): array
    {
        $notice = $this->boundaryNotice;
        if ($notice !== '' && ! $this->showUnverifiedBoundary) {
            return MapContext::coverageFit($this->municipalityCode, $this->inventories);
        }
        if ($this->publicZone !== null) {
            $b = $this->bboxOf($this->publicZone['feature']['geometry']['coordinates'], true);

            return $b === null ? [] : [[$b[0], $b[1]], [$b[2], $b[3]]];
        }
        if ($this->areaBoundary['points'] !== []) {
            return $this->areaBoundary['points'];
        }
        $points = [];
        foreach ($this->mapState['parcels'] as $p) {
            $b = $this->bboxOf($p['geometry']['coordinates'], true);
            $points = [...$points, ...($b === null ? [$p['point']] : [[$b[0], $b[1]], [$b[2], $b[3]]])];
        }
        if ($points !== []) {
            $lat = array_column($points, 0);
            $lng = array_column($points, 1);

            return [[min($lat), min($lng)], [min($lat), max($lng)], [max($lat), max($lng)], [max($lat), min($lng)]];
        }
        $b = $this->municipality['bounds'] ?? null;

        return $b === null ? [] : [[$b[0], $b[1]], [$b[2], $b[3]]];
    }

    /** [minLat, minLng, maxLat, maxLng] of GeoJSON coordinates ([lng, lat]). */
    private function bboxOf(array $coordinates, bool $geojson): ?array
    {
        $lat = $lng = [];
        $walk = function ($value) use (&$walk, &$lat, &$lng) {
            if (is_array($value) && isset($value[0]) && is_numeric($value[0]) && isset($value[1]) && is_numeric($value[1])) {
                $lng[] = (float) $value[0];
                $lat[] = (float) $value[1];

                return;
            }
            foreach ((array) $value as $inner) {
                $walk($inner);
            }
        };
        $walk($coordinates);

        return $lat === [] ? null : [min($lat), min($lng), max($lat), max($lng)];
    }

    #[Computed]
    public function boundaryNotice(): string
    {
        $points = $this->areaBoundary['points'];

        return $points === [] ? '' : MapContext::coverageNotice($this->municipalityCode, $points, $this->inventories);
    }

    #[Computed]
    public function hasCartography(): bool
    {
        return collect($this->inventories)->contains(fn ($m) => $m['parcels'] > 0) || $this->mapState['parcels'] !== [];
    }

    #[Computed]
    public function outcomes(): array
    {
        return array_values(array_unique(['Tutti', 'Nessun esito', ...ScoutingMap::outcomes($this->membership)]));
    }

    /** Dati per la mappa (aggiornamento del componente x-gestionale.map). */
    #[Computed]
    public function mapPayload(): array
    {
        $state = $this->mapState;
        $empty = ['type' => 'FeatureCollection', 'features' => []];
        $area = $this->areaBoundary;
        $showBoundary = ! ($this->boundaryNotice !== '' && ! $this->showUnverifiedBoundary) && $area['geojson'] !== null;
        $shape = $showBoundary ? ['type' => 'FeatureCollection', 'features' => [['type' => 'Feature', 'properties' => ['closed' => true], 'geometry' => $area['geojson']]]] : null;
        $parcel = $state['parcel'];

        return [
            'mode' => $this->drawing ? 'draw-polygon' : 'view',
            'parcels' => ['type' => 'FeatureCollection', 'features' => $state['features']],
            'buildings' => $state['buildings'],
            'zoneShape' => $this->publicZone ? ['type' => 'FeatureCollection', 'features' => [$this->publicZone['feature']]] : $empty,
            'boundaryShape' => $shape,
            'polygon' => $this->draftPolygon,
            'draftSeed' => $this->draftSeed,
            'selectedParcel' => $parcel['id'] ?? null,
            'selectedOutline' => $this->mapSelection['key'] ?? null,
            'fit' => $this->fitBoundary,
            'centerRequest' => $this->center,
            'outlines' => 'cartography',
            'outlineVersion' => $this->municipalityCode.':'.($this->publicZone['id'] ?? ''),
        ];
    }

    /** Dati delle sole zone del selettore Quartieri. */
    #[Computed]
    public function pickerPayload(): array
    {
        $shapes = ScoutingMap::neighborhoodShapes($this->municipalityCode);
        $b = $this->bboxOf(array_map(fn ($f) => $f['geometry']['coordinates'], $shapes['features']), true);
        $features = array_map(fn ($f) => [...$f, 'properties' => [...$f['properties'], 'selected' => $this->pickedPublicZone === $f['properties']['id']]], $shapes['features']);

        return ['areas' => ['type' => 'FeatureCollection', 'features' => $features], 'fit' => $b === null ? [] : [[$b[0], $b[1]], [$b[2], $b[3]]]];
    }

    // ---- Schermata

    public function setView(string $view): void
    {
        if ($this->drawing || ! in_array($view, ['Mappa', 'Stato'], true)) {
            return;
        }
        $this->view = $view;
    }

    public function changeMunicipality(string $code): void
    {
        $code = strtoupper($code);
        if (! in_array($code, array_column($this->inventories, 'code'), true)) {
            return;
        }
        $this->showUnverifiedBoundary = false;
        $this->publicZoneId = null;
        $this->choosingPublicZone = false;
        $this->municipalityCode = $code;
        $this->closeSelection();
        $this->street = 'Tutte';
        $this->outcome = 'Tutti';
        $this->planId = '';
        $this->center++;
    }

    public function updatedMunicipalityCode(string $code): void
    {
        $this->changeMunicipality($code);
    }

    public function updatedStreet(): void
    {
        $this->mapSelection = null;
        $this->selected = null;
        $this->center++;
    }

    public function updatedOutcome(): void
    {
        $this->updatedStreet();
    }

    public function updatedPlanId(): void
    {
        $this->updatedStreet();
    }

    public function centerMap(): void
    {
        $this->center++;
    }

    public function toggleUnverifiedBoundary(): void
    {
        $this->showUnverifiedBoundary = ! $this->showUnverifiedBoundary;
        $this->center++;
    }

    public function closeSelection(): void
    {
        $this->selected = null;
        $this->mapSelection = null;
        $this->showUnits = false;
    }

    // ---- Quartieri e frazioni REKO

    public function openPublicZones(): void
    {
        if ($this->drawing) {
            return;
        }
        $this->pickedPublicZone = $this->publicZoneId;
        $this->choosingPublicZone = true;
    }

    public function closePublicZones(): void
    {
        $this->choosingPublicZone = false;
    }

    public function closeCartography(): void
    {
        $this->cartographyOpen = false;
    }

    public function applyPublicZone(): void
    {
        $this->setPublicZone($this->pickedPublicZone);
    }

    public function clearPublicZone(): void
    {
        $this->setPublicZone(null);
    }

    private function setPublicZone(?int $id): void
    {
        if ($id !== null && ! ScoutingMap::neighborhoodExists($this->municipalityCode, $id)) {
            $this->addError('command', 'Il quartiere non appartiene al Comune selezionato.');
            $this->dispatch('crm-notice', type: 'error', text: 'Il quartiere non appartiene al Comune selezionato.');

            return;
        }
        $this->publicZoneId = $id;
        $this->choosingPublicZone = false;
        $this->closeSelection();
        $this->center++;
    }

    public function removePublicZone(): void
    {
        $this->publicZoneId = null;
        $this->closeSelection();
        $this->center++;
    }

    #[On('map-area-selected')]
    public function areaSelected(string $key, int|string $id): void
    {
        if ($key === 'public-zone-map' && ScoutingMap::neighborhoodExists($this->municipalityCode, (int) $id)) {
            $this->pickedPublicZone = (int) $id;
        }
    }

    // ---- Selezione sulla mappa

    #[On('map-parcel-selected')]
    public function parcelSelected(string $key, int|string $id): void
    {
        if ($key !== self::MAP || $this->drawing) {
            return;
        }
        $this->mapSelection = null;
        $this->selected = (int) $id;
        $this->showUnits = false;
    }

    #[On('map-outline-selected')]
    public function outlineSelected(string $key, array $outline): void
    {
        if ($key !== self::MAP || $this->drawing) {
            return;
        }
        $code = strtoupper((string) ($outline['code'] ?? ''));
        $parts = array_map(fn ($f) => (string) ($outline[$f] ?? ''), ['section', 'sheet', 'parcel']);
        $kind = strtoupper((string) ($outline['kind'] ?? ''));
        if ($code !== $this->municipalityCode || ! in_array($kind, ['F', 'T'], true) || $parts[1] === '' || $parts[2] === '' || mb_strlen(implode('', $parts)) > 60) {
            return;
        }
        $key = ScoutingMap::outlineKey($code, $parts[0], $parts[1], $parts[2], $kind);
        $existing = collect($this->mapState['parcels'])->first(fn ($p) => ScoutingMap::outlineKey($p['code'], $p['section'], $p['sheet'], $p['parcel'], $p['kind']) === $key);
        $this->showUnits = false;
        if ($existing) {
            $this->selected = $existing['id'];
            $this->mapSelection = null;
        } else {
            $this->selected = null;
            $this->mapSelection = ['code' => $code, 'kind' => $kind, 'section' => $parts[0], 'sheet' => $parts[1], 'parcel' => $parts[2], 'key' => $key];
        }
    }

    /** Cartografia del Comune nel riquadro visibile (municipal-map-layer.tsx). Chiamata dal browser, senza ridisegnare la pagina. */
    #[Renderless]
    public function cartography(string $bounds): array
    {
        $this->guard();
        $parts = array_map('floatval', explode(',', $bounds));
        if (count($parts) !== 4 || $parts[0] < -90 || $parts[2] > 90 || $parts[1] < -180 || $parts[3] > 180 || $parts[0] >= $parts[2] || $parts[1] >= $parts[3]) {
            return ['error' => 'Cartografia non disponibile.'];
        }
        try {
            return ScoutingMap::outlines($this->municipalityCode, $parts, $this->publicZone['id'] ?? null);
        } catch (\Throwable $e) {
            report($e);

            return ['error' => 'Cartografia non disponibile.'];
        }
    }

    // ---- Assegnazione operatore

    public function assignOperator(int $parcelId, string $userId, ?string $success = null): void
    {
        $this->command(fn () => app(AssignParcelOperator::class)->handle($this->actor(), $parcelId, (int) $userId), $success ?? 'Operatore della particella assegnato');
        unset($this->mapState);
    }

    // ---- Disegno dei confini

    public function startDraw(bool $edit): void
    {
        $area = $this->area;
        $points = [];
        if ($edit) {
            $points = $this->areaBoundary['ring'];
            if ($area === null || $points === null) {
                $this->boundaryError = 'Questa zona è composta da più aree: i confini non si modificano punto per punto.';

                return;
            }
        }
        $this->draftId = $edit ? $area->id : null;
        $this->draftPolygon = $edit ? $points : [];
        $this->draftName = $edit ? $area->name : '';
        $this->draftOperator = (string) ($edit ? $area->operator_user_id : ($this->membership->role === 'scout' ? $this->membership->user_id : ($this->scouts->first()?->user_id ?? '')));
        $this->draftRevision = $edit ? (string) Commands::revision($area) : '';
        $this->draftToken = (string) Str::uuid();
        $this->boundaryError = '';
        $this->drawing = true;
        $this->draftSeed++;
        $this->closeSelection();
    }

    public function cancelDraw(): void
    {
        $this->drawing = false;
        $this->draftPolygon = [];
        $this->boundaryError = '';
        $this->closeSelection();
    }

    /** @param  array<int, mixed>  $vertices  [[lat, lng], …] disegnati nel browser */
    public function saveBoundary(array $vertices): void
    {
        $this->boundaryError = '';
        try {
            $membership = $this->actor();
            $boundary = \App\Gestionale\Scouting\ZoneBoundary::validate($vertices);
            $issue = MapContext::coverageNotice($this->municipalityCode, $boundary, $this->inventories);
            if ($issue !== '') {
                throw new CommandRejected($issue);
            }
            $zone = app(SaveZoneBoundary::class)->handle($membership, [
                'code' => $this->municipalityCode, 'name' => trim($this->draftName), 'boundary' => $boundary, 'token' => $this->draftId === null ? $this->draftToken : '',
                'operator_id' => $membership->role === 'scout' ? $membership->user_id : $this->draftOperator,
            ], $this->draftId, $this->draftId === null ? null : $this->draftRevision);
        } catch (CommandRejected $e) {
            $this->boundaryError = $e->getMessage();
            $this->dispatch('crm-notice', type: 'error', text: $e->getMessage());

            return;
        }
        $this->dispatch('crm-notice', type: 'success', text: 'Confini salvati. La zona è assegnata all’agente indicato; cartografia e dati importati restano invariati.');
        unset($this->allZones, $this->zones, $this->area, $this->areaBoundary, $this->mapState);
        $this->planId = (string) $zone->id;
        $this->drawing = false;
        $this->draftPolygon = [];
        $this->center++;
        $this->closeSelection();
    }

    // ---- Stato del censimento

    public function selectPlan(int $id): void
    {
        $zone = $this->allZones->firstWhere('id', $id);
        $codes = $zone ? CensusPlan::zoneMunicipalities($zone) : [];
        if (count($codes) === 1 && in_array($codes[0], array_column($this->inventories, 'code'), true)) {
            $this->changeMunicipality($codes[0]);
            $this->planId = (string) $id;
        } else {
            $this->boundaryError = 'Scegli il Comune della zona prima di aprire la mappa.';
        }
        $this->view = 'Mappa';
    }

    public function openZoneForm(?int $id = null): void
    {
        if ($this->membership->role !== 'admin') {
            return;
        }
        $zone = $id === null ? null : $this->allZones->firstWhere('id', $id);
        $this->zoneFormId = $zone?->id;
        $this->zf = [
            'name' => $zone?->name ?? '', 'description' => $zone?->notes ?? '', 'operator_id' => (string) ($zone?->operator_user_id ?? ''),
            'status' => $zone?->status ?? 'Pianificata', 'start' => $zone?->starts_on?->toDateString() ?? '',
            'plan' => $zone ? CensusPlan::format((array) $zone->plan) : '',
        ];
        $this->zfRevision = (string) Commands::revision($zone);
        $this->zfToken = (string) Str::uuid();
        $this->zfError = '';
        $this->zoneFormOpen = true;
    }

    public function closeZoneForm(): void
    {
        $this->zoneFormOpen = false;
    }

    public function saveZone(): void
    {
        $this->zfError = '';
        try {
            $zone = app(SaveCensusPlan::class)->handle($this->actor(), [
                'name' => $this->zf['name'], 'description' => $this->zf['description'], 'operator_id' => $this->zf['operator_id'], 'status' => $this->zf['status'],
                'start' => $this->zf['start'], 'plan' => CensusPlan::parse((string) $this->zf['plan']), 'token' => $this->zoneFormId === null ? $this->zfToken : '',
            ], $this->zoneFormId, $this->zoneFormId === null ? null : $this->zfRevision);
        } catch (CommandRejected $e) {
            $this->zfError = $e->getMessage();

            return;
        }
        $this->dispatch('crm-notice', type: 'success', text: 'Piano di censimento salvato');
        unset($this->allZones, $this->zones, $this->area, $this->areaBoundary, $this->mapState);
        $this->zoneFormOpen = false;
        if ($this->planId === '') {
            $this->planId = (string) $zone->id;
        }
    }

    /** La zona di "Stato del censimento": quella scelta, altrimenti la prima. */
    #[Computed]
    public function planZone(): ?ScoutingZone
    {
        return $this->allZones->firstWhere('id', (int) $this->planId) ?? $this->allZones->first();
    }

    #[Computed]
    public function progress(): ?array
    {
        return $this->planZone ? CensusPlan::progress($this->planZone) : null;
    }

    // ---- Elenco immobili della particella

    public function openUnits(): void
    {
        $this->unitsPage = 1;
        $this->showUnits = true;
    }

    public function closeUnits(): void
    {
        $this->showUnits = false;
    }

    public function unitsPrevious(): void
    {
        $this->unitsPage = max(1, $this->unitsPage - 1);
    }

    public function unitsNext(): void
    {
        $this->unitsPage++;
    }

    public function addSelectedCatalogParcel(): void
    {
        $this->guard();
        if ($this->drawing) {
            return;
        }
        $identity = $this->selectedIdentity;
        if ($identity === null) {
            return;
        }
        if ($identity['kind'] !== 'F') {
            $this->dispatch('crm-notice', type: 'error', text: 'Acquisisci dal catalogo solo le unità del Catasto Fabbricati.');

            return;
        }
        $this->command(function () use ($identity) {
            if (! $this->canAcquireCatalog) {
                throw new CommandRejected('Acquisizione del catalogo non abilitata per il tuo pacchetto.', 403);
            }
            $parcel = ParcelPanel::find($identity['code'], $identity['section'], $identity['sheet'], $identity['parcel']);
            if ($parcel === null || ParcelPanel::units((int) $parcel->id)['total'] === 0) {
                throw new CommandRejected('I subalterni di questo Comune non sono ancora disponibili nel catalogo REKO.');
            }
            $pick = ['code' => strtoupper($identity['code']), 'section' => strtoupper($identity['section']), 'sheet' => $identity['sheet'], 'parcel' => $identity['parcel']];
            if (collect($this->catalogPicks)->contains(fn ($current) => $current === $pick)) {
                return true;
            }
            if (count($this->catalogPicks) >= 25) {
                throw new CommandRejected('Seleziona da 1 a 25 particelle per acquisizione.');
            }
            $this->catalogPicks[] = $pick;

            return true;
        }, 'Particella aggiunta all’elenco di acquisizione');
    }

    public function removeCatalogPick(int $index): void
    {
        $this->guard();
        if (array_key_exists($index, $this->catalogPicks)) {
            array_splice($this->catalogPicks, $index, 1);
        }
    }

    public function acquireCatalog(): void
    {
        $actor = $this->guard();
        if ($this->drawing) {
            return;
        }
        $result = $this->command(fn () => app(AcquireCatalogParcels::class)->handle($actor, [
            'parcels' => $this->catalogPicks, 'token' => $this->catalogToken ?: (string) Str::uuid(),
        ]), 'Immobili del catalogo aggiunti al censimento');
        if ($result !== null) {
            $this->catalogAcquired();
        }
    }

    public function acquireSelectedCatalogParcel(): void
    {
        $actor = $this->guard();
        if ($this->drawing || $this->selectedIdentity === null) {
            return;
        }
        $i = $this->selectedIdentity;
        if ($i['kind'] !== 'F') {
            $this->dispatch('crm-notice', type: 'error', text: 'Acquisisci dal catalogo solo le unità del Catasto Fabbricati.');

            return;
        }
        $result = $this->command(fn () => app(AcquireCatalogParcels::class)->handle($actor, [
            'parcels' => [['code' => $i['code'], 'section' => $i['section'], 'sheet' => $i['sheet'], 'parcel' => $i['parcel']]],
            'token' => (string) Str::uuid(),
        ]), 'Immobili aggiunti al censimento, senza duplicati');
        if ($result !== null) {
            $this->catalogAcquired(false);
        }
    }

    private function catalogAcquired(bool $clearPicks = true): void
    {
        if ($clearPicks) {
            $this->catalogPicks = [];
        }
        $this->catalogToken = (string) Str::uuid();
        unset($this->acquiredCatalogUnits, $this->mapState, $this->selectedCatalogParcel);
        $this->center++;
    }
}; ?>

@php
    $isAdmin = $this->membership->role === 'admin';
    $inventories = $this->inventories;
    $municipality = $this->municipality;
    $zones = $this->zones;
    $drawing = $this->drawing;
    $notice = $this->boundaryNotice;
    $label = fn (string $key) => $key;
@endphp
<div class="crm-scouting-page" x-data x-on:keydown.escape.window="if (! document.querySelector('dialog[open]')) $wire.closeSelection()">
    <div class="crm-page-head"><div><p class="proto-eyebrow">REKO Gestionale</p><h1>Mappa e zone</h1><p class="crm-muted">Scegli il Comune e tocca una particella per vedere gli immobili.</p></div>
        @can('agency-permission', 'sister.import')<div class="crm-actions"><a class="crm-btn secondary" href="{{ route('gestionale.archive.index', ['importSister' => 1, 'municipalityCode' => $municipalityCode]) }}" wire:navigate><x-gestionale.lucide name="upload" :size="16" />Importa da SISTER</a></div>@endcan
    </div>
    <nav class="crm-tabs" aria-label="Mappa e zone">
        <button type="button" wire:click="setView('Mappa')" @if ($view === 'Mappa') aria-current="page" @endif>Mappa</button>
        <button type="button" wire:click="setView('Stato')" @disabled($drawing) @if ($view === 'Stato') aria-current="page" @endif>Stato del censimento</button>
    </nav>

    @if ($view === 'Stato')
        @include('gestionale.scouting.stato')
    @else
        @include('gestionale.scouting.mappa')
    @endif

    @if ($zoneFormOpen)
        @include('gestionale.scouting.zone-form')
    @endif
    @if ($choosingPublicZone)
        @include('gestionale.scouting.neighborhood-picker')
    @endif
    @if ($cartographyOpen)
        @include('gestionale.scouting.cartography')
    @endif
</div>
