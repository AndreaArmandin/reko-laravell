@php
    use App\Gestionale\Scouting\Format;
    use App\Gestionale\Scouting\ParcelPanel;
    use App\Gestionale\Scouting\ScoutingMap;

    $state = $this->mapState;
    $identity = $this->selectedIdentity;
    $catalogParcel = $this->selectedCatalogParcel;
    $parcel = $state['parcel'];
    $hasCatalog = ScoutingMap::hasCatalog($municipalityCode);
    $summary = collect([$this->publicZone['name'] ?? '', $street !== 'Tutte' ? $street : '', $outcome !== 'Tutti' ? $outcome : ''])->filter()->implode(' · ');
@endphp

<div class="crm-zone-layout">
    <div class="crm-toolbar crm-zone-heading">
        <label class="crm-field"><span>Comune</span><select wire:model.live="municipalityCode" @disabled($drawing)>@forelse ($inventories as $inventory)<option value="{{ $inventory['code'] }}">{{ $inventory['municipality'] }}</option>@empty<option value="F205">Milano</option>@endforelse</select></label>
        <span>{{ $summary !== '' ? $summary : 'Tutto il Comune' }}</span>
    </div>
    <details class="crm-zone-filters" wire:ignore.self>
        <summary>Filtri e strumenti della mappa</summary>
        <div class="crm-toolbar">
            <button type="button" class="crm-btn secondary" wire:click="openPublicZones" @disabled($drawing)>Quartieri e frazioni REKO</button>
            @if ($zones->isNotEmpty())
                <label class="crm-field"><span>Zona operativa dell’agenzia</span><select wire:model.live="planId" @disabled($drawing)><option value="">Tutte le zone operative</option>@foreach ($zones as $zone)<option value="{{ $zone->id }}">{{ $zone->name }}</option>@endforeach</select></label>
            @endif
            <label class="crm-field"><span>Zona / via</span><select wire:model.live="street" @disabled($drawing)><option value="Tutte">Tutte</option>@foreach ($state['addresses'] as $address)<option value="{{ $address }}">{{ Format::normalizeAddress($address) }}</option>@endforeach</select></label>
            <label class="crm-field"><span>Esito</span><select wire:model.live="outcome" @disabled($drawing)>@foreach ($this->outcomes as $item)<option>{{ $item }}</option>@endforeach</select></label>
            <button type="button" class="crm-btn secondary" wire:click="centerMap"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="22" x2="18" y1="12" y2="12"/><line x1="6" x2="2" y1="12" y2="12"/><line x1="12" x2="12" y1="6" y2="2"/><line x1="12" x2="12" y1="22" y2="18"/></svg>Centra mappa</button>
            @if ($canSister)
                @if ($drawing)
                    <button type="button" class="crm-btn" disabled><x-gestionale.lucide name="file-up" :size="16" />Importa da Sister</button>
                @else
                    <a class="crm-btn" href="{{ route('gestionale.archive.index', ['importSister' => 1, 'municipalityCode' => $municipalityCode]) }}" wire:navigate><x-gestionale.lucide name="file-up" :size="16" />Importa da Sister</a>
                @endif
            @endif
            <button type="button" class="crm-btn secondary" wire:click="$set('cartographyOpen', true)" @disabled($drawing)>Cartografia REKO</button>
            @if ($this->canDraw && ! $drawing)
                <button type="button" class="crm-btn secondary" wire:click="startDraw(false)">Disegna confini della zona</button>
                <button type="button" class="crm-btn secondary" wire:click="startDraw(true)" @disabled($this->area === null) @if ($this->area === null) title="Seleziona una zona Scout da modificare" @endif>Modifica confini</button>
            @endif
        </div>
    </details>

    @if ($this->publicZone !== null)
        <div class="crm-actions"><strong>Zona REKO: {{ $this->publicZone['name'] }}</strong><span>{{ \App\Gestionale\Scouting\MapContext::NEIGHBORHOOD_NOTICE }}</span><button type="button" class="crm-link" wire:click="removePublicZone">Rimuovi filtro quartiere</button></div>
    @endif

    @if (! $drawing && $hasCatalog)
        <section class="crm-panel">
            <h2>Acquisisci dal catalogo REKO</h2>
            <p>{{ $this->canAcquireCatalog ? 'Seleziona una particella sulla mappa e aggiungila all’elenco. Verranno acquisiti soltanto gli immobili presenti, senza creare proprietari o doppioni.' : 'Acquisizione non abilitata. Il pacchetto e i limiti saranno configurati dall’amministratore.' }}</p>
            <div class="crm-actions">
                <button type="button" class="crm-btn secondary" wire:click="addSelectedCatalogParcel" @disabled(! $this->canAcquireCatalog || $identity === null || $identity['kind'] !== 'F' || ! ScoutingMap::hasCatalog($identity['code']) || count($catalogPicks) >= 25)>Aggiungi particella selezionata</button>
                <button type="button" class="crm-btn" wire:click="acquireCatalog" @disabled(! $this->canAcquireCatalog || $catalogPicks === [])>Acquisisci {{ count($catalogPicks) }} {{ count($catalogPicks) === 1 ? 'particella' : 'particelle' }}</button>
            </div>
            @foreach ($catalogPicks as $index => $pick)
                <button type="button" class="crm-link" wire:key="catalog-pick-{{ $index }}" wire:click="removeCatalogPick({{ $index }})">F. {{ $pick['section'] !== '' ? $pick['section'].'/' : '' }}{{ $pick['sheet'] }} · P. {{ $pick['parcel'] }} · rimuovi</button>
            @endforeach
            <small>Massimo 25 particelle per operazione. Nessun acquisto o addebito: i pacchetti commerciali non sono ancora attivi.</small>
        </section>
    @endif
    @if (! $hasCatalog)
        <p class="crm-panel">{{ $municipality['municipality'] ?? '' }}: esplora i confini catastali e seleziona una particella. L’elenco dei subalterni di questo Comune non è ancora acquisito. I confini sulla mappa non sostituiscono l’elenco degli immobili.</p>
    @endif

    @if ($drawing)
        <form class="crm-panel crm-form" x-on:submit.prevent="$wire.saveBoundary($store.gmaps?.['scout-map']?.polygon || [])">
            <strong>{{ $draftId === null ? 'Disegna una nuova zona' : 'Modifica confini della zona' }}</strong>
            <p>Clicca sulla mappa per aggiungere i vertici. Trascina i punti gialli per spostarli. Non vengono modificati i confini catastali né il piano di censimento.</p>
            <div class="crm-form-grid">
                <label class="crm-field"><span>Nome zona</span><input wire:model="draftName" maxlength="120" required></label>
                @if ($draftId === null && $isAdmin)
                    <label class="crm-field"><span>Operatore della zona</span><select wire:model="draftOperator" required>@foreach ($this->scouts as $scout)<option value="{{ $scout->user_id }}">{{ $scout->user->name }}</option>@endforeach</select></label>
                @endif
            </div>
            <div class="crm-actions">
                <button type="button" class="crm-btn secondary" x-bind:disabled="! ($store.gmaps?.['scout-map']?.count || 0)" x-on:click="window.dispatchEvent(new CustomEvent('gestionale-map:command', {detail: {key: 'scout-map', command: 'undo'}}))">Annulla ultimo punto</button>
                <button type="button" class="crm-btn secondary" wire:click="cancelDraw">Annulla modifiche</button>
                <button type="submit" class="crm-btn" x-bind:disabled="($store.gmaps?.['scout-map']?.count || 0) < 3">Salva confini · <span x-text="$store.gmaps?.['scout-map']?.count || 0">0</span> vertici</button>
            </div>
            @if ($boundaryError !== '')<p role="alert">{{ $boundaryError }}</p>@endif
        </form>
    @endif

    @if ($state['missing'] > 0 && $this->hasCartography)
        <p class="crm-muted">{{ $state['missing'] }} particelle senza corrispondenza nella cartografia caricata. Verifica Comune, sezione, foglio e particella negli elenchi; non vengono attribuiti perimetri approssimativi.</p>
    @endif

    @if (! $drawing && $identity === null)
        <div class="crm-map-guidance" role="status">
            <p>{{ $this->hasCartography ? 'Clicca una particella per vedere gli immobili presenti in REKO.' : 'Cartografia REKO in caricamento. Gli immobili del censimento restano consultabili negli elenchi.' }}</p>
        </div>
    @endif

    @if ($notice !== '')
        <p role="alert">{{ $notice }} <button type="button" class="crm-link" wire:click="toggleUnverifiedBoundary">{{ $showUnverifiedBoundary ? 'Torna al Comune' : 'Mostra confine salvato da verificare' }}</button></p>
    @endif

    <section class="crm-scout-map">
        @if ($this->fitBoundary !== [])
            <x-gestionale.map key="scout-map" :mode="$drawing ? 'draw-polygon' : 'view'" :config="$this->mapPayload" :sync="$this->mapPayload" label="Mappa delle particelle e delle zone" />
        @else
            <p role="status">Cartografia del Comune non presente: nessuna posizione viene attribuita. Gli elenchi restano consultabili.</p>
        @endif
        @if ($identity !== null && ! $drawing)
            @php
                // I dati del censimento solo per le particelle che il ruolo può raggiungere (CensusScope), come nell'originale.
                $accessible = $catalogParcel !== null && ScoutingMap::accessible($this->membership, (int) $catalogParcel->id);
                $census = $accessible ? ParcelPanel::census($this->membership, (int) $catalogParcel->id) : null;
                $availableCatalogUnits = $catalogParcel !== null ? ParcelPanel::units((int) $catalogParcel->id) : ['total' => 0];
                $measures = $catalogParcel !== null ? ParcelPanel::measurements((int) $catalogParcel->id) : null;
                $sisterImport = $canSister ? route('gestionale.archive.index', ['importSister' => 1, 'municipalityCode' => $identity['code']]) : null;
                $agentId = $parcel === null ? null : ($state['agents'][$parcel['id']] ?? null);
                $assignHint = 'Finché non scegli un agente, la particella resta visibile solo all’amministratore.';
            @endphp
            <aside class="crm-map-selection crm-map-selection--compact" aria-label="Particella selezionata">
                <div class="crm-map-selection-heading"><strong>{{ Format::normalizeAddress($parcel['address'] ?? '') ?: 'Particella '.$identity['parcel'] }}</strong><button type="button" class="crm-icon-btn" aria-label="Chiudi scheda particella" wire:click="closeSelection"><x-gestionale.lucide name="x" :size="20" /></button></div>
                <span>{{ $identity['municipality'] }} · F. {{ $identity['section'] !== '' ? $identity['section'].'/' : '' }}{{ $identity['sheet'] }} · P. {{ $identity['parcel'] }}</span>
                {{-- Call 07/10, punto 20: identificativo catastale leggibile, es. “D205 foglio 90 particella 34”. --}}
                <p><strong>{{ $identity['code'] ?? '' }}@if ($identity['section'] !== '') sezione {{ $identity['section'] }}@endif foglio {{ $identity['sheet'] }} particella {{ $identity['parcel'] }}</strong></p>
                @if ($census !== null)
                    {{-- Call 07/10, punto 20: gli immobili sono quelli già nell’archivio catastale dell’agenzia, non quelli del catalogo. --}}
                    <p>{{ $census['units'] }} immobili in archivio · {{ $census['owners'] }} proprietari</p>
                    <span class="crm-pill">{{ $census['outcome'] }}</span>
                @endif
                @if (! ScoutingMap::hasCatalog($identity['code']))
                    <p>Cartografia disponibile. I subalterni di {{ $identity['municipality'] }} non sono ancora stati acquisiti: non vengono associati immobili di altri Comuni.</p>
                @else
                    @if ($identity['kind'] === 'T' && $isAdmin)
                        <section class="crm-parcel-units">
                            <strong>Catasto Terreni</strong>
                            <p>Acquisisci il perimetro catastale verificato per archiviarlo senza creare unità o intestatari.</p>
                            <button type="button" class="crm-btn" wire:click="acquireTerrainParcel" wire:loading.attr="disabled" wire:target="acquireTerrainParcel">Acquisisci perimetro</button>
                        </section>
                    @endif
                    <div class="crm-parcel-units" aria-label="Immobili della particella">
                        <strong>{{ $availableCatalogUnits['total'] }} immobili presenti in REKO</strong>
                        @if ($availableCatalogUnits['total'] === 0)
                            <p>Non ci sono ancora unità acquisite per questa particella. La copertura catastale è in aggiornamento.</p>
                        @else
                            <button type="button" class="crm-btn secondary" wire:click="openUnits">Apri elenco immobili</button>
                            @if ($identity['kind'] === 'F')
                                @if ($this->canAcquireCatalog)
                                    @php($alreadyAcquired = $acquiredKey === implode('|', [$identity['code'], $identity['section'], $identity['sheet'], $identity['parcel']]))
                                    <button type="button" class="crm-btn" wire:click="acquireSelectedCatalogParcel" wire:loading.attr="disabled" wire:target="acquireSelectedCatalogParcel" @disabled($alreadyAcquired)>{{ $alreadyAcquired ? 'Immobili presenti nel censimento' : 'Aggiungi al censimento' }}</button>
                                @else
                                    <p>Per aggiungerli al censimento serve l’abilitazione dell’amministratore.</p>
                                @endif
                            @endif
                            @if ($isAdmin && $accessible)
                                <label class="crm-field"><span>Agente acquisizioni che vedrà gli immobili</span><select wire:change="assignOperator({{ $catalogParcel->id }}, $event.target.value, 'La particella è ora visibile all’agente acquisizioni selezionato')"><option value="" disabled @selected($agentId === null)>Da assegnare</option>@foreach ($this->scouts as $scout)<option value="{{ $scout->user_id }}" @selected($agentId == $scout->user_id)>{{ $scout->user->name }}</option>@endforeach</select><small>{{ $assignHint }}</small></label>
                            @endif
                        @endif
                    </div>
                @endif
                @if ($measures !== null)
                    <details class="crm-parcel-measurements" wire:ignore.self>
                        <summary>Superfici della particella</summary>
                        <dl><dt>Particella / lotto</dt><dd>{{ Format::area($measures['lot']) }}</dd><dt>Coperto a terra</dt><dd>{{ Format::area($measures['covered']) }}</dd><dt>Spazio scoperto</dt><dd>{{ Format::area($measures['uncovered']) }}</dd></dl>
                        <small>{{ $measures['notice'] }}</small>
                    </details>
                @endif
                <details wire:ignore.self>
                    <summary>Dati del censimento e altre azioni</summary>
                    @if ($census === null)<small>Nessun dato del censimento accessibile per questa particella.</small>@endif
                    <div class="crm-actions">
                        @if ($sisterImport)<a class="crm-btn secondary" href="{{ $sisterImport }}" wire:navigate>Importa da Sister</a>@endif
                        @if ($parcel !== null && $this->canDraw)<a class="crm-btn secondary" href="{{ route('gestionale.archive.index', ['parcel' => $parcel['id']]) }}" wire:navigate>Apri censimento <x-gestionale.lucide name="arrow-up-right" :size="14" /></a>@endif
                    </div>
                    @if ($parcel !== null && $isAdmin)
                        <label class="crm-field"><span>Operatore assegnato</span><select wire:change="assignOperator({{ $parcel['id'] }}, $event.target.value)"><option value="" disabled @selected($agentId === null)>Assegna operatore</option>@foreach ($this->scouts as $scout)<option value="{{ $scout->user_id }}" @selected($agentId == $scout->user_id)>{{ $scout->user->name }}</option>@endforeach</select></label>
                    @endif
                </details>
            </aside>
        @endif
    </section>

    <details class="crm-context-help">
        <summary>Come usare la mappa</summary>
        <p>Una particella è una porzione di terreno identificata da foglio e numero. Può contenere più immobili (subalterni). Sister è il servizio dell’Agenzia delle Entrate: con “Importa da Sister” puoi incollare i dati già consultati, dopo aver controllato l’anteprima.</p>
    </details>
    <div class="proto-map-legend" aria-label="Legenda della mappa">
        <span><i style="background:#BDEBC8"></i>Particelle</span>
        <span><i style="background:#D7DADD"></i>Fabbricati</span>
        <span><i style="background:#FFC93C"></i>Fabbricato selezionato</span>
    </div>

    @if ($this->canAcquireCatalog)
        <section class="crm-panel">
            <h2>Immobili acquisiti dal catalogo</h2>
            <div class="crm-table-wrap"><table>
                <thead><tr><th>Indirizzo</th><th>Foglio · particella · subalterno</th><th>Categoria</th><th>Consistenza</th></tr></thead>
                <tbody>@foreach ($this->acquiredCatalogUnits as $unit)
                    <tr wire:key="acquired-catalog-{{ sha1($unit['catalog_key']) }}">
                        <td>{{ $unit['address'] ?: 'Non indicato' }}</td>
                        <td>{{ $unit['section'] !== '' ? $unit['section'].'/' : '' }}{{ $unit['sheet'] }} · {{ $unit['number'] }} · {{ $unit['subalterno'] ?: 'Non indicato' }}</td>
                        <td>{{ $unit['category'] }}</td>
                        <td>{{ $unit['consistency'] ?: 'Non indicata' }}</td>
                    </tr>
                @endforeach</tbody>
            </table></div>
        </section>
    @endif

    @if ($showUnits && $catalogParcel !== null && $identity !== null && ! $drawing)
        @php($units = ParcelPanel::units((int) $catalogParcel->id, $unitsPage))
        <x-gestionale.scouting.dialog :title="'Immobili · particella '.$identity['parcel']" close="$wire.closeUnits()" :wide="true">
            <div class="crm-parcel-catalog-dialog">
                <p>{{ $identity['municipality'] }} · Foglio {{ $identity['section'] !== '' ? $identity['section'].'/' : '' }}{{ $identity['sheet'] }} · Particella {{ $identity['parcel'] }}</p>
                <div class="crm-parcel-units" aria-label="Elenco immobili della particella">
                    <p>{{ $units['total'] }} immobili · pagina {{ $units['page'] }} di {{ $units['pages'] }}</p>
                    <ul>@foreach ($units['rows'] as $unit)<li wire:key="unit-{{ $unit['key'] }}"><strong>{{ $unit['sub'] !== '' ? 'Subalterno '.$unit['sub'] : 'Subalterno non indicato' }} · {{ $unit['category'] }}</strong><span>{{ $unit['consistency'] }}</span>@if ($unit['address'] !== '')<span>{{ $unit['address'] }}</span>@endif</li>@endforeach</ul>
                    @if ($units['pages'] > 1)
                        <div class="crm-actions"><button type="button" class="crm-btn secondary" wire:click="unitsPrevious" @disabled($units['page'] === 1)>Precedenti</button><button type="button" class="crm-btn secondary" wire:click="unitsNext" @disabled($units['page'] === $units['pages'])>Successivi</button></div>
                    @endif
                </div>
            </div>
        </x-gestionale.scouting.dialog>
    @endif
</div>
