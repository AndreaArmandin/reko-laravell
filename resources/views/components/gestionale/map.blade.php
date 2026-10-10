@props([
    // Chiave della mappa: distingue più mappe nella stessa pagina e compare in ogni evento.
    'key' => 'map',
    // view | pick-point | draw-polygon
    'mode' => 'view',
    // Configurazione iniziale: center {lat,lng}, zoom, point {lat,lng,radius?}, polygon [[lat,lng],…],
    // parcels/buildings/zoneShape (GeoJSON), fit [[lat,lng],…], outlines (nome del metodo Livewire che
    // restituisce le particelle del riquadro visibile), maxVertices.
    'config' => [],
    // Dati che cambiano con la pagina: ogni modifica li invia alla mappa già creata (wire:ignore).
    'sync' => null,
    'label' => 'Mappa',
    'height' => null,
])

{{--
    Mappa del Gestionale (prototype-map.tsx / location-map.tsx). MapLibre vive dentro wire:ignore:
    gli aggiornamenti arrivano dall'elemento "sync" qui sotto, che Livewire ricrea a ogni cambio dei dati.

    Eventi Livewire emessi (listener con #[On('nome')]):
      map-point-picked      key, lat, lng            (mode="pick-point")
      map-polygon-changed   key, polygon             (mode="draw-polygon", vertici [lat, lng])
      map-parcel-selected   key, id                  (clic su una particella del censimento)
      map-outline-selected  key, outline             (clic su una particella della cartografia)
    Comandi dal browser: window.dispatchEvent(new CustomEvent('gestionale-map:command', {detail: {key, command: 'undo'|'center'}})).
    Stato condiviso nel browser: $store.gmaps[key] = { count, polygon }.
--}}
<div {{ $attributes->class(['gmap']) }} @if ($height) style="height: {{ $height }}" @endif
    data-gestionale-map="{{ $key }}" wire:ignore wire:key="gestionale-map-{{ $key }}"
    x-data="gestionaleMap(@js(array_replace($config, ['key' => $key, 'mode' => $mode])))">
    <div x-ref="canvas" class="gmap-canvas" role="application" aria-label="{{ $label }}"></div>
    <div class="gmap-loading crm-skeleton-map" role="status" x-show="! loaded">Caricamento mappa…</div>
    <div class="gmap-notice" x-show="notice" x-cloak><div class="crm-map-cartography-notice" role="status" x-text="notice"></div></div>
</div>
@if ($sync !== null)
    <div class="gmap-sync" aria-hidden="true" wire:key="gestionale-map-sync-{{ $key }}-{{ md5(json_encode($sync)) }}"
        x-data="{ payload: @js($sync) }" x-init="$nextTick(() => window.dispatchEvent(new CustomEvent('gestionale-map:update', { detail: { ...payload, key: @js($key) } })))"></div>
@endif
