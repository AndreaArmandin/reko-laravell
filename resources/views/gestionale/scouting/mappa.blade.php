@php
    $state = $this->mapState;
    $identity = $this->selectedIdentity;
    $catalogParcel = $this->selectedCatalogParcel;
@endphp

<div class="crm-zone-layout">
    <div class="crm-toolbar crm-zone-heading">
        <div>
            <h2>Esplora il territorio</h2>
            <p class="crm-muted">{{ count($state['parcels']) }} particelle visibili sulla mappa.</p>
        </div>
        <div class="crm-actions">
            @if (! $drawing)
                <button type="button" class="crm-btn secondary" wire:click="centerMap">Centra mappa</button>
                @if ($this->canDraw)<button type="button" class="crm-btn" wire:click="startDraw(false)">Disegna zona</button>@endif
            @endif
        </div>
    </div>

    <details class="crm-zone-filters" @if ($street !== 'Tutte' || $outcome !== 'Tutti' || $planId !== '') open @endif>
        <summary>Filtri e strumenti della mappa</summary>
        <div class="crm-toolbar">
            <label class="crm-field"><span>Comune</span><select wire:model.live="municipalityCode">@foreach ($inventories as $inventory)<option value="{{ $inventory['code'] }}">{{ $inventory['municipality'] }} ({{ $inventory['province'] }})</option>@endforeach</select></label>
            <label class="crm-field"><span>Zona operativa dell’agenzia</span><select wire:model.live="planId"><option value="">Tutte le zone operative</option>@foreach ($zones as $zone)<option value="{{ $zone->id }}">{{ $zone->name }}</option>@endforeach</select></label>
            <label class="crm-field"><span>Zona / via</span><select wire:model.live="street"><option>Tutte</option>@foreach ($state['addresses'] as $address)<option>{{ $address }}</option>@endforeach</select></label>
            <label class="crm-field"><span>Esito</span><select wire:model.live="outcome">@foreach ($this->outcomes as $item)<option>{{ $item }}</option>@endforeach</select></label>
        </div>
        <div class="crm-actions">
            <button type="button" class="crm-btn secondary" wire:click="openPublicZones">Quartieri e frazioni REKO</button>
            <button type="button" class="crm-btn secondary" wire:click="$set('cartographyOpen', true)">Cartografia REKO</button>
            @if ($this->publicZone !== null)<button type="button" class="crm-link" wire:click="removePublicZone">Rimuovi {{ $this->publicZone['name'] }}</button>@endif
            @if ($this->area !== null && $this->area->has_boundary && $this->canDraw)<button type="button" class="crm-btn secondary" wire:click="startDraw(true)">Modifica confini</button>@endif
        </div>
        @if ($notice !== '')<p class="crm-muted">{{ $notice }} <button type="button" class="crm-link" wire:click="toggleUnverifiedBoundary">{{ $showUnverifiedBoundary ? 'Nascondi confini' : 'Mostra comunque i confini' }}</button></p>@endif
    </details>

    @if ($state['missing'] > 0)<p class="crm-muted">{{ $state['missing'] }} particelle senza poligono: restano consultabili nell’archivio catastale.</p>@endif

    @if ($drawing)
        <section class="crm-panel crm-form" aria-label="Disegna zona">
            <h2>{{ $draftId === null ? 'Disegna una nuova zona' : 'Modifica i confini della zona' }}</h2>
            <p class="crm-muted">Seleziona i vertici sulla mappa, poi salva i confini.</p>
            <div class="crm-toolbar">
                <label class="crm-field"><span>Nome della zona</span><input wire:model="draftName" maxlength="180"></label>
                @if ($isAdmin)
                    <label class="crm-field"><span>Agente acquisizioni</span><select wire:model="draftOperator"><option value="">Scegli un agente</option>@foreach ($this->scouts as $scout)<option value="{{ $scout->user_id }}">{{ $scout->user->name }}</option>@endforeach</select></label>
                @endif
                <span x-text="($store.gmaps?.['scout-map']?.count || 0) + ' punti'">0 punti</span>
            </div>
            @if ($boundaryError !== '')<p role="alert" class="crm-contact-warning">{{ $boundaryError }}</p>@endif
            <div class="crm-actions">
                <button type="button" class="crm-btn secondary" x-on:click="window.dispatchEvent(new CustomEvent('gestionale-map:command', {detail: {key: 'scout-map', command: 'undo'}}))">Annulla ultimo punto</button>
                <button type="button" class="crm-btn secondary" wire:click="cancelDraw">Annulla disegno</button>
                <button type="button" class="crm-btn" x-on:click="$wire.saveBoundary($store.gmaps?.['scout-map']?.polygon || [])">Salva zona</button>
            </div>
        </section>
    @endif

    <div class="crm-scout-map">
        <x-gestionale.map key="scout-map" :mode="$drawing ? 'draw-polygon' : 'view'" :config="$this->mapPayload" :sync="$this->mapPayload" label="Mappa delle particelle e delle zone" />
        @if ($identity !== null && ! $drawing)
            <aside class="crm-map-selection crm-map-selection--compact" aria-label="Particella selezionata">
                <div class="crm-map-selection-heading"><strong>{{ $identity['kind'] === 'T' ? 'Terreni' : 'Fabbricati' }} · Particella {{ $identity['parcel'] }}</strong><button type="button" class="crm-icon-btn" wire:click="closeSelection" aria-label="Chiudi particella">×</button></div>
                <small>{{ $identity['municipality'] }} · Foglio {{ $identity['sheet'] }}@if ($identity['section'] !== '') · Sezione {{ $identity['section'] }}@endif</small>
                @if ($catalogParcel !== null)
                    @php
                        $census = \App\Gestionale\Scouting\ParcelPanel::census($this->membership, (int) $catalogParcel->id);
                        $availableCatalogUnits = \App\Gestionale\Scouting\ParcelPanel::units((int) $catalogParcel->id);
                    @endphp
                    <p>{{ $census['units'] }} immobili in archivio · {{ $census['owners'] }} proprietari</p>
                    <span class="crm-pill">{{ $census['outcome'] }}</span>
                    @if ($isAdmin && $this->scouts->isNotEmpty())
                        <label class="crm-field"><span>Agente assegnato</span><select wire:change="assignOperator({{ $catalogParcel->id }}, $event.target.value)"><option value="">Da assegnare</option>@foreach ($this->scouts as $scout)<option value="{{ $scout->user_id }}" @selected(($state['agents'][$catalogParcel->id] ?? null) == $scout->user_id)>{{ $scout->user->name }}</option>@endforeach</select></label>
                    @endif
                    <button type="button" class="crm-btn secondary" wire:click="openUnits">Immobili della particella</button>
                    @if ($identity['kind'] === 'F' && $availableCatalogUnits['total'] > 0)
                        @if ($this->canAcquireCatalog)
                            <button type="button" class="crm-btn" wire:click="acquireSelectedCatalogParcel">Aggiungi al censimento</button>
                        @else
                            <p class="crm-muted">Per aggiungerli al censimento serve l’abilitazione dell’Amministratore.</p>
                        @endif
                    @endif
                    @if ($showUnits)
                        @php
                            $units = \App\Gestionale\Scouting\ParcelPanel::units((int) $catalogParcel->id, $unitsPage);
                        @endphp
                        <div class="crm-parcel-units"><strong>{{ $units['total'] }} immobili presenti in REKO</strong>
                            @if ($units['rows'] === [])<p>Nessun immobile presente nel catalogo.</p>@else
                                <ul>@foreach ($units['rows'] as $unit)<li><strong>Sub {{ $unit['sub'] }}</strong> · {{ $unit['category'] }} · {{ $unit['consistency'] }}<br><small>{{ $unit['address'] }}</small></li>@endforeach</ul>
                                <div class="crm-actions"><button type="button" class="crm-btn secondary" wire:click="unitsPrevious" @disabled($units['page'] <= 1)>Precedenti</button><span>{{ $units['page'] }} / {{ $units['pages'] }}</span><button type="button" class="crm-btn secondary" wire:click="unitsNext" @disabled($units['page'] >= $units['pages'])>Successivi</button></div>
                            @endif
                        </div>
                    @endif
                @else
                    <p>Questa particella è visibile sulla cartografia, ma non ha ancora dati catastali in archivio.</p>
                @endif
            </aside>
        @endif
    </div>

    @if (! $drawing && $identity === null)
        <div class="crm-map-guidance" role="status">
            <p>{{ $this->hasCartography ? 'Clicca una particella per vedere gli immobili presenti in REKO.' : 'Cartografia REKO in caricamento. Gli immobili del censimento restano consultabili negli elenchi.' }}</p>
        </div>
    @endif

    <details class="crm-context-help">
        <summary>Come usare la mappa</summary>
        <p>Una particella è una porzione di terreno identificata da foglio e numero. Può contenere più immobili (subalterni). Sister è il servizio dell’Agenzia delle Entrate: con “Importa da Sister” puoi incollare i dati già consultati, dopo aver controllato l’anteprima.</p>
    </details>
    <div class="proto-map-legend" aria-label="Legenda della mappa">
        <span><i style="background:#BDEBC8"></i>Particelle</span>
        <span><i style="background:#D7DADD"></i>Fabbricati</span>
        <span><i style="background:#FFC93C"></i>Fabbricato selezionato</span>
    </div>

    @if (\App\Gestionale\Scouting\ScoutingMap::hasCatalog($municipalityCode))
        <section class="crm-panel crm-form" aria-labelledby="catalog-acquisition-title">
            <h2 id="catalog-acquisition-title">Acquisisci dal catalogo REKO</h2>
            @if ($this->canAcquireCatalog)
                <p>Seleziona una particella sulla mappa e aggiungila all’elenco. Saranno acquisiti gli immobili eleggibili presenti nel catalogo, senza creare proprietari o duplicare le schede.</p>
            @else
                <p>Acquisizione non abilitata. L’Amministratore può configurare un pacchetto e il relativo limite in Impostazioni.</p>
            @endif
            <div class="crm-actions">
                <button type="button" class="crm-btn secondary" wire:click="addSelectedCatalogParcel" @disabled(! $this->canAcquireCatalog || $identity === null || $identity['kind'] !== 'F' || $drawing)>Aggiungi particella selezionata</button>
                <button type="button" class="crm-btn" wire:click="acquireCatalog" @disabled(! $this->canAcquireCatalog || $catalogPicks === [] || $drawing)>Acquisisci {{ count($catalogPicks) }} {{ count($catalogPicks) === 1 ? 'particella' : 'particelle' }}</button>
            </div>
            @if ($catalogPicks !== [])
                <ul class="crm-catalog-picks" aria-label="Particelle da acquisire">
                    @foreach ($catalogPicks as $index => $pick)
                        <li wire:key="catalog-pick-{{ $index }}">
                            <span>{{ $pick['code'] }} · F. {{ $pick['section'] !== '' ? $pick['section'].'/' : '' }}{{ $pick['sheet'] }} · P. {{ $pick['parcel'] }}</span>
                            <button type="button" class="crm-link" wire:click="removeCatalogPick({{ $index }})">Rimuovi</button>
                        </li>
                    @endforeach
                </ul>
            @endif
            <small>Massimo 25 particelle per operazione. Gli immobili vengono acquisiti senza intestatari.</small>
        </section>

        @if ($this->canAcquireCatalog)
            <section class="crm-panel" aria-labelledby="acquired-catalog-title">
                <h2 id="acquired-catalog-title">Immobili acquisiti dal catalogo</h2>
                @if ($this->acquiredCatalogUnits === [])
                    <p class="crm-muted">Non ci sono ancora immobili acquisiti dal catalogo.</p>
                @else
                    <div class="crm-table-wrap"><table>
                        <thead><tr><th>Indirizzo</th><th>Comune · foglio · particella · subalterno</th><th>Categoria</th><th>Consistenza</th></tr></thead>
                        <tbody>@foreach ($this->acquiredCatalogUnits as $unit)
                            <tr wire:key="acquired-catalog-{{ sha1($unit['catalog_key']) }}">
                                <td>{{ $unit['address'] ?: 'Non indicato' }}</td>
                                <td>{{ $unit['municipality'] }} · F. {{ $unit['section'] !== '' ? $unit['section'].'/' : '' }}{{ $unit['sheet'] }} · P. {{ $unit['number'] }} · Sub. {{ $unit['subalterno'] ?: '—' }}</td>
                                <td>{{ $unit['category'] ?: 'Non indicata' }}</td>
                                <td>{{ $unit['consistency'] ?: 'Non indicata' }}</td>
                            </tr>
                        @endforeach</tbody>
                    </table></div>
                    <small>Mostrati fino a 100 immobili; l’Archivio catastale contiene l’elenco completo.</small>
                @endif
            </section>
        @endif
    @endif

</div>
