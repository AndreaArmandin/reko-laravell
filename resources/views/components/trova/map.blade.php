@props(['config', 'note' => null, 'key' => 'map'])

@php($drawing = (bool) ($config['draw'] ?? false))

{{-- Mappa di Trova (catalog-area.tsx + reko-map-3d.tsx; in disegno search-zone-picker.tsx "polygon").
     wire:ignore: MapLibre e i vertici disegnati restano vivi fra un aggiornamento Livewire e l'altro --}}
<div class="{{ $drawing ? 'trova-zone-focused' : 'reko-circle-picker' }}" wire:ignore wire:key="{{ $key }}" x-data="trovaMap(@js($config))">
    <div class="reko-catalog-map{{ $drawing ? ' trova-focus-map' : '' }}">
        <div class="reko-3d" data-testid="reko-map-3d">
            <div class="reko-3d-canvas-wrap">
                <div x-ref="canvas" class="reko-3d-canvas" aria-label="Mappa 3D interattiva"></div>
                <div class="reko-3d-loading" role="status" x-show="! loaded">Caricamento mappa 3D…</div>
            </div>
            <div class="reko-map-attribution" aria-label="Fonti della mappa">Base cartografica: <a href="https://openfreemap.org/" target="_blank" rel="noopener noreferrer">OpenFreeMap</a> · © <a href="https://openmaptiles.org/" target="_blank" rel="noopener noreferrer">OpenMapTiles</a> · © <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap</a></div>
            <div class="reko-3d-tools" aria-label="Controlli del fabbricato 3D">
                <button type="button" :aria-pressed="three" x-on:click="toggle3d()">Vista 3D</button>
                <button type="button" disabled aria-pressed="false">Isola selezione</button>
                <button type="button" x-on:click="rotate()">Ruota ↻</button>
                <button type="button" x-on:click="reset()">Ripristina vista</button>
            </div>
            <p class="reko-3d-note" role="status" x-text="note"></p>
            <details class="reko-3d-sources">
                <summary>Fonti e precisione della mappa</summary>
                <p>OpenFreeMap · © OpenMapTiles · © OpenStreetMap contributors (ODbL). Sagome degli edifici REKO separate dai lotti, dove collegate alla particella. Non è una ricostruzione degli interni. Il collegamento alle sagome è geometrico, non prova di proprietà o disponibilità. <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">Licenza dei dati</a></p>
            </details>
        </div>
    </div>

    @if ($drawing)
        {{-- Barra del disegno, come in Trova: Chiudi zona / Annulla punto, poi Ridisegna --}}
        <div class="trova-zone-toolbar">
            <template x-if="editing"><button type="button" :disabled="count < 3" x-on:click="closeZone()">Chiudi zona</button></template>
            <template x-if="editing"><button type="button" :disabled="count === 0" x-on:click="undoPoint()">Annulla punto</button></template>
            <template x-if="! editing">
                <button type="button" x-on:click="redraw()">Ridisegna</button>
            </template>
            <span x-text="count + (count === 1 ? ' punto' : ' punti')"></span>
        </div>
        <p class="trova-map-status" :role="error ? 'alert' : 'status'" x-text="error || (editing ? 'Tocca almeno tre vertici, poi chiudi la zona.' : 'Zona pronta. Premi Avanti.')"></p>
    @endif
</div>
