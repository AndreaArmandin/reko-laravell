<?php

use App\Gestionale\Actions\Properties\SaveProperty;
use App\Gestionale\Commands;
use App\Gestionale\Livewire\HandlesCommands;
use App\Gestionale\Properties\PropertyFields;
use App\Gestionale\Properties\PropertyLinks;
use App\Gestionale\Properties\PropertyStatus;
use App\Gestionale\Scouting\MapContext;
use App\Gestionale\Scouting\ScoutingMap;
use App\Gestionale\Questionnaire\Questionnaire;
use App\Models\AgencyMembership;
use App\Models\CadastralUnit;
use App\Models\Property;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * Nuovo immobile / Modifica immobile a portafoglio (properties.tsx PropertyForm): scheda commerciale, distinta
 * dall'archivio catastale. Il mandato non si modifica qui (l'originale non lo espone): resta com'è.
 * La scelta del punto sulla mappa è obbligatoria per una scheda nuova; i punti nuovi o spostati vengono
 * confrontati con la copertura cartografica caricata, che non coincide necessariamente con il confine comunale.
 */
new #[Layout('layouts::gestionale'), Title('Immobile a portafoglio')] class extends Component {
    use HandlesCommands;

    public ?Property $property = null;
    public string $expectedRevision = '';
    public string $idempotencyKey = '';

    public string $title = '';
    public string $code = '';
    public string $address = '';
    public string $civic = '';
    public string $city = '';
    public string $province = '';
    public string $postal_code = '';
    public string $zone = '';
    public string $description = '';
    public string $strengths = '';
    public string $internal_notes = '';
    public string $status = 'Non attivo';
    public string $statusNotice = '';
    public ?int $agent_user_id = null;

    /** @var array<string, mixed> */
    public array $features = [];
    /** @var array<string, string> */
    public array $publication = ['portals' => '', 'status' => SaveProperty::DEFAULT_PUBLICATION_STATUS, 'date' => '', 'url' => ''];
    /** @var list<int|string> */
    public array $assignedScouts = [];

    /** @var list<int|string> */
    public array $linkedUnits = [];
    /** @var list<int|string> */
    public array $linkedOwners = [];
    public bool $linksOpen = false;
    public string $unitSearch = '';
    public string $ownerSearch = '';
    public bool $unitsOnlySelected = false;
    public bool $ownersOnlySelected = false;

    public float $latitude = 45.4642;
    public float $longitude = 9.19;
    public bool $pointChosen = false;
    public string $positionError = '';

    public bool $confirm_duplicate = false;

    /** Unità scelte in Trova ("Crea immobile a portafoglio"): precompilano il collegamento e il Comune. */
    #[Url(as: 'unitIds')]
    public array $cadastral_unit_ids = [];

    public function mount(?Property $property = null): void
    {
        if ($property?->exists) {
            $this->authorize('update', $property);
            $this->property = $property;
            $this->expectedRevision = (string) Commands::revision($property);
            $this->fill([
                'agent_user_id' => $property->agent_user_id,
                'title' => $property->title, 'code' => (string) $property->code, 'address' => (string) $property->address,
                'civic' => (string) $property->civic, 'city' => (string) ($property->city ?: $property->municipality?->name),
                'province' => (string) $property->province, 'postal_code' => (string) $property->postal_code, 'zone' => (string) $property->zone,
                'status' => $property->status, 'description' => (string) $property->description,
                'strengths' => (string) $property->strengths, 'internal_notes' => (string) $property->internal_notes,
                'assignedScouts' => array_map('strval', $property->assigned_scout_user_ids ?? []),
            ]);
            $this->features = $this->toForm($property->features ?? []);
            $this->publication = [
                'portals' => (string) ($property->publication['portals'] ?? ''),
                'status' => (string) ($property->publication['status'] ?? SaveProperty::DEFAULT_PUBLICATION_STATUS),
                'date' => (string) ($property->publication['date'] ?? ''),
                'url' => (string) ($property->publication['url'] ?? ''),
            ];
            $this->linkedUnits = array_map('strval', PropertyLinks::currentUnitIds($property));
            $this->linkedOwners = array_map('strval', PropertyLinks::currentOwnerIds($property));
            $point = DB::selectOne('SELECT ST_Y(location) AS lat, ST_X(location) AS lng FROM properties WHERE id = ? AND agency_id = ?', [$property->id, $property->agency_id]);
            if ($point?->lat !== null) {
                $this->latitude = (float) $point->lat;
                $this->longitude = (float) $point->lng;
            }
        } else {
            $this->authorize('create', Property::class);
            $this->idempotencyKey = (string) Str::uuid();
            $this->agent_user_id = $this->actor()->user_id;
            // Come il nuovo immobile dell'originale: vendita, appartamento, abitazione principale, non attivo.
            $this->features = ['operation' => 'Acquisto', 'typology' => 'Appartamento', 'purpose' => ['Abitazione principale']];
            $ids = array_values(array_unique(array_slice(array_filter(array_map('intval', $this->cadastral_unit_ids)), 0, 100)));
            if ($ids !== []) {
                $ids = array_values(array_filter($ids, fn ($id) => PropertyLinks::inaccessibleUnits($this->actor(), [$id], null) === []));
                $this->linkedUnits = array_map('strval', $ids);
                $unit = CadastralUnit::query()->with('parcel.municipality')->whereIn('id', $ids)->first();
                $this->city = (string) $unit?->parcel?->municipality?->name;
            }
        }
    }

    /** Valori salvati → valori del form (sì / no per i campi booleani, elenchi per le caselle). */
    private function toForm(array $features): array
    {
        foreach (PropertyFields::BOOLEAN as $key) {
            if (isset($features[$key]) && is_bool($features[$key])) {
                $features[$key] = $features[$key] ? 'yes' : 'no';
            }
        }
        foreach (PropertyFields::CHECKBOXES as $key) {
            if (isset($features[$key]) && ! is_array($features[$key])) {
                $features[$key] = [(string) $features[$key]];
            }
        }
        $features['tags'] = array_values((array) ($features['tags'] ?? []));

        return $features;
    }

    #[Computed]
    public function backUrl(): string
    {
        return $this->property ? route('gestionale.properties.show', $this->property) : route('gestionale.properties.index');
    }

    #[Computed]
    public function admin(): bool
    {
        return $this->actor()->isAdmin();
    }

    /** Le domande con un campo, non riservate: etichette e opzioni dei campi (data.settings.questions). */
    #[Computed]
    public function questions(): array
    {
        $map = [];
        foreach (Questionnaire::forAgency($this->actor()->agency_id)->questions() as $question) {
            if (! empty($question['field']) && empty($question['sensitive'])) {
                $map[$question['field']] = $question;
            }
        }

        return $map;
    }

    #[Computed]
    public function zones(): array
    {
        return array_values(array_filter(Questionnaire::forAgency($this->actor()->agency_id)->zones(), fn ($zone) => $zone !== 'Tutta Milano'));
    }

    #[Computed]
    public function isMilan(): bool
    {
        return (MapContext::municipalityForCity($this->city)['code'] ?? null) === 'F205';
    }

    /** @return list<array<string,mixed>> Comuni con cartografia disponibile e relativa copertura caricata. */
    #[Computed]
    public function cartography(): array
    {
        return ScoutingMap::municipalities();
    }

    #[Computed]
    public function positionNotice(): string
    {
        return MapContext::propertyPointNotice($this->city, ['lat' => $this->latitude, 'lng' => $this->longitude], $this->cartography);
    }

    /** Referente: gli utenti attivi che non sono scout. */
    #[Computed]
    public function agents()
    {
        return AgencyMembership::query()->where('agency_id', $this->actor()->agency_id)->whereIn('role', ['admin', 'crm'])->active()->with('user')->get()->sortBy(fn ($m) => $m->user->name);
    }

    #[Computed]
    public function scouts()
    {
        return AgencyMembership::query()->where('agency_id', $this->actor()->agency_id)->where('role', 'scout')->active()->with('user')->get()->sortBy(fn ($m) => $m->user->name);
    }

    #[Computed]
    public function unitList(): array
    {
        return $this->linksOpen
            ? PropertyLinks::unitOptions($this->actor(), array_map('intval', $this->linkedUnits), $this->unitSearch, $this->unitsOnlySelected, $this->property)
            : ['options' => collect(), 'matching' => 0, 'unavailable' => 0];
    }

    #[Computed]
    public function ownerList(): array
    {
        return $this->linksOpen
            ? PropertyLinks::ownerOptions($this->actor(), array_map('intval', $this->linkedOwners), $this->ownerSearch, $this->ownersOnlySelected, $this->property)
            : ['options' => collect(), 'matching' => 0, 'unavailable' => 0];
    }

    /** changeContract(): lo stato deve essere compatibile con il contratto. */
    public function updatedFeaturesOperation(mixed $operation): void
    {
        if (! in_array($this->status, PropertyStatus::options((string) $operation), true)) {
            $this->status = 'Non attivo';
            $this->statusNotice = 'Contratto cambiato: scegli lo stato dell’immobile. Per ora l’immobile è non attivo.';
        } else {
            $this->statusNotice = '';
        }
    }

    public function updatedStatus(): void
    {
        $this->statusNotice = '';
    }

    public function openLinks(): void
    {
        $this->linksOpen = true;
    }

    #[On('map-point-picked')]
    public function pickPoint(string $key = '', mixed $lat = null, mixed $lng = null): void
    {
        if ($key !== 'property-point' || ! is_numeric($lat) || ! is_numeric($lng) || ! is_finite((float) $lat) || ! is_finite((float) $lng)
            || abs((float) $lat) > 90 || abs((float) $lng) > 180) {
            return;
        }
        $this->latitude = (float) $lat;
        $this->longitude = (float) $lng;
        $this->pointChosen = true;
        $this->positionError = '';
    }

    public function save()
    {
        if ($this->property) {
            $this->authorize('update', $this->property);
        } else {
            $this->authorize('create', Property::class);
        }

        $original = null;
        if ($this->property) {
            $savedPoint = DB::selectOne('SELECT ST_Y(location) AS lat, ST_X(location) AS lng FROM properties WHERE id = ? AND agency_id = ?', [$this->property->id, $this->property->agency_id]);
            if ($savedPoint?->lat !== null && $savedPoint?->lng !== null) {
                $original = ['lat' => (float) $savedPoint->lat, 'lng' => (float) $savedPoint->lng];
            }
        }
        $this->positionError = MapContext::propertyPointSaveError($this->city,
            ['lat' => $this->latitude, 'lng' => $this->longitude], $this->cartography, $original, $this->pointChosen);
        if ($this->positionError !== '') {
            return;
        }

        $input = [
            'title' => $this->title, 'code' => $this->code, 'address' => $this->address, 'civic' => $this->civic, 'city' => $this->city,
            'province' => $this->province, 'postal_code' => $this->postal_code, 'zone' => $this->zone, 'status' => $this->status,
            'description' => $this->description, 'strengths' => $this->strengths, 'internal_notes' => $this->internal_notes,
            'features' => $this->features, 'publication' => $this->publication,
            'cadastral_unit_ids' => array_map('intval', $this->linkedUnits), 'owner_ids' => array_map('intval', $this->linkedOwners),
            'confirm_duplicate' => $this->confirm_duplicate,
        ];
        if ($this->actor()->isAdmin()) {
            $input['agent_user_id'] = $this->agent_user_id;
            $input['assigned_scout_user_ids'] = array_map('intval', $this->assignedScouts);
        }
        if ($this->pointChosen) {
            $input['latitude'] = $this->latitude;
            $input['longitude'] = $this->longitude;
        }
        if ($this->property) {
            $input['expected_updated_at'] = $this->expectedRevision;
        } else {
            $input['idempotency_key'] = $this->idempotencyKey;
        }

        $saved = $this->command(fn () => app(SaveProperty::class)->handle($this->actor(), $input, $this->property), 'Scheda immobile salvata.');

        if ($saved) {
            return $this->redirectRoute('gestionale.properties.show', $saved, navigate: true);
        }
    }
}; ?>

<div>
    <dialog class="proto-dialog reko-prototype proto-dialog-wide" aria-labelledby="property-form-title" wire:ignore.self
        x-data x-init="$el.showModal()" x-on:cancel.prevent="Livewire.navigate(@js($this->backUrl))"
        x-on:click="if ($event.target === $el) Livewire.navigate(@js($this->backUrl))">
        <div class="proto-dialog-heading">
            <div><p class="proto-eyebrow">Simulazione REKO</p><h2 id="property-form-title">{{ $property ? 'Modifica immobile a portafoglio' : 'Nuovo immobile a portafoglio' }}</h2></div>
            <a href="{{ $this->backUrl }}" wire:navigate class="inline-flex size-8 items-center justify-center rounded-lg hover:bg-muted" aria-label="Chiudi"><x-gestionale.lucide name="x" :size="16" /></a>
        </div>

        <form class="crm-form" wire:submit="save">
            <p class="crm-muted">Scheda commerciale, distinta dall’archivio catastale. Lascia da verificare i dati che non conosci.</p>
            <div class="crm-form-grid">
                <label class="crm-field"><span>Titolo immobile</span><input wire:model="title" required maxlength="160"></label>
                <label class="crm-field"><span>Codice interno</span><input wire:model="code" maxlength="40"></label>
                <label class="crm-field"><span>Contratto</span>
                    <select wire:model.live="features.operation"><option value="">Da scegliere</option><option value="Acquisto">Vendita</option><option value="Locazione">Affitto</option></select>
                </label>
                <label class="crm-field"><span>Stato commerciale</span>
                    <select wire:model.live="status">@foreach (PropertyStatus::options($features['operation'] ?? null) as $value)<option value="{{ $value }}">{{ $value }}</option>@endforeach</select>
                    @if ($statusNotice !== '')<small role="status">{{ $statusNotice }}</small>@endif
                </label>
                <label class="crm-field"><span>Referente</span>
                    <select wire:model="agent_user_id" @disabled(! $this->admin)>@foreach ($this->agents as $agent)<option value="{{ $agent->user_id }}">{{ $agent->user->name }}</option>@endforeach</select>
                </label>
                <label class="crm-field"><span>Via e indirizzo</span><input wire:model="address" required maxlength="255"></label>
                <label class="crm-field"><span>Civico</span><input wire:model="civic" maxlength="40"></label>
                <label class="crm-field"><span>Comune</span><input wire:model.live.blur="city" required maxlength="120"></label>
                <label class="crm-field"><span>Zona</span>
                    @if ($this->isMilan)
                        <select wire:model="zone"><option value="">Da definire</option>
                            @if ($zone !== '' && ! in_array($zone, $this->zones, true))<option value="{{ $zone }}">{{ $zone }} · valore conservato</option>@endif
                            @foreach ($this->zones as $z)<option value="{{ $z }}">{{ $z }}</option>@endforeach
                        </select>
                    @else
                        <input wire:model="zone" maxlength="160" placeholder="Zona o località del Comune">
                        @if ($zone !== '' && in_array($zone, $this->zones, true))<small role="status">Zona da verificare rispetto al Comune. Il valore non viene sostituito automaticamente.</small>@endif
                    @endif
                </label>
                <label class="crm-field"><span>Provincia</span><input wire:model="province" maxlength="2"></label>
                <label class="crm-field"><span>CAP</span><input wire:model="postal_code" maxlength="12"></label>
            </div>

            <div class="crm-form-grid">
                @foreach (PropertyFields::BASE as $id)
                    @include('gestionale.partials.property-field', ['spec' => PropertyFields::spec($id, $this->questions[$id] ?? null)])
                @endforeach
            </div>

            <label class="crm-field"><span>Descrizione immobile</span>
                <textarea wire:model="description" maxlength="12000" placeholder="Descrivi l’immobile: le caratteristiche indicate possono corrispondere ai tag delle richieste."></textarea>
            </label>
            <x-gestionale.crm-tag-input model="features.tags" :value="$features['tags'] ?? []" label="Tag immobile" :live="false" />

            @if ($this->admin)
                <details class="crm-form-section"><summary>Accesso Operatori 1</summary>
                    @foreach ($this->scouts as $scout)
                        <label class="crm-check" wire:key="scout-{{ $scout->user_id }}"><input type="checkbox" wire:model="assignedScouts" value="{{ $scout->user_id }}">{{ $scout->user->name }}</label>
                    @endforeach
                </details>
            @endif

            <details class="crm-form-section"><summary>Caratteristiche, dotazioni e dati energetici</summary>
                <div class="crm-form-grid">
                    @foreach (PropertyFields::EXTRA as $id)
                        @include('gestionale.partials.property-field', ['spec' => PropertyFields::spec($id, $this->questions[$id] ?? null)])
                    @endforeach
                </div>
            </details>

            <details class="crm-form-section"><summary>Commerciale, investimento, terreno e box</summary>
                <p class="crm-muted">Completa soltanto i campi pertinenti. Il rendimento è una stima dimostrativa, non una promessa.</p>
                <div class="crm-form-grid">
                    @foreach (PropertyFields::COMMERCIAL as $id)
                        @include('gestionale.partials.property-field', ['spec' => PropertyFields::spec($id, $this->questions[$id] ?? null)])
                    @endforeach
                </div>
            </details>

            <details class="crm-form-section" x-on:toggle="$nextTick(() => window.dispatchEvent(new Event('resize')))"><summary>Posizione sulla mappa</summary>
                <div class="crm-location-map">
                    <x-gestionale.map key="property-point" mode="pick-point" label="Posizione dell’immobile" :config="['point' => ['lat' => $latitude, 'lng' => $longitude, 'radius' => 0.1], 'zoom' => 13]" :sync="['point' => ['lat' => $latitude, 'lng' => $longitude, 'radius' => 0.1]]" />
                </div>
                <p class="crm-muted">{{ $property ? 'La posizione salvata resta invariata finché non scegli un nuovo punto.' : 'Il punto iniziale non verrà salvato senza una tua scelta.' }} {{ number_format($latitude, 5, '.', '') }}, {{ number_format($longitude, 5, '.', '') }}.</p>
                @if ($this->positionNotice !== '')<p role="status">{{ $this->positionNotice }}</p>@endif
            </details>

            <details class="crm-form-section"><summary>Pubblicazione e testi</summary>
                <div class="crm-form-grid">
                    <label class="crm-field"><span>Portali</span><input wire:model="publication.portals" maxlength="300"></label>
                    <label class="crm-field"><span>Stato pubblicazione</span><input wire:model="publication.status" maxlength="160"></label>
                    <label class="crm-field"><span>Data pubblicazione annotata</span><input type="date" wire:model="publication.date"></label>
                    <label class="crm-field"><span>Collegamento annuncio (HTTPS)</span><input type="url" wire:model="publication.url" maxlength="1500"></label>
                </div>
                <label class="crm-field"><span>Punti di forza</span><textarea wire:model="strengths"></textarea></label>
                <label class="crm-field"><span>Note riservate</span><textarea wire:model="internal_notes"></textarea></label>
            </details>

            <details class="crm-form-section" x-on:toggle="if ($el.open) $wire.openLinks()"><summary>Collega unità catastali e proprietari</summary>
                <p class="crm-muted">Collegamenti manuali ed espliciti. Importare da Sister non crea automaticamente immobili a portafoglio.</p>
                @if ($linksOpen)
                    <x-gestionale.searchable-links legend="Unità catastali" model="linkedUnits" search-model="unitSearch" only-model="unitsOnlySelected"
                        :options="$this->unitList['options']" :matching="$this->unitList['matching']" :selected="count($linkedUnits)" :unavailable="$this->unitList['unavailable']"
                        :query="$unitSearch" placeholder="Comune, indirizzo, foglio o particella" />
                    <x-gestionale.searchable-links legend="Proprietari" model="linkedOwners" search-model="ownerSearch" only-model="ownersOnlySelected"
                        :options="$this->ownerList['options']" :matching="$this->ownerList['matching']" :selected="count($linkedOwners)" :unavailable="$this->ownerList['unavailable']"
                        :query="$ownerSearch" placeholder="Nome o codice fiscale" />
                @else
                    <p class="crm-muted" role="status" wire:loading wire:target="openLinks">Caricamento…</p>
                @endif
            </details>

            <details @if ($errors->has('confirm_duplicate')) open @endif><summary>Gestione dei duplicati</summary>
                <label class="crm-check"><input type="checkbox" wire:model="confirm_duplicate">Ho verificato che è un immobile demo distinto allo stesso indirizzo</label>
            </details>

            @if ($positionError !== '')<p role="alert">{{ $positionError }} Apri “Posizione sulla mappa” per verificare.</p>@endif
            <div class="crm-form-footer">
                @if ($errors->any())<p class="crm-error" role="alert">{{ $errors->first() }}</p>@endif
                <button class="crm-btn" wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">Salva immobile e aggiorna abbinamenti</span><span wire:loading wire:target="save">Salvataggio…</span>
                </button>
            </div>
        </form>
    </dialog>
</div>
