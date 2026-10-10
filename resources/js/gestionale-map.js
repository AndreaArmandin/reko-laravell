/*
 * Mappa del Gestionale (components/prototype/prototype-map.tsx, location-map.tsx, public-neighborhood-picker.tsx),
 * in versione Livewire/MapLibre. Il componente Blade è <x-gestionale.map>.
 *
 * Modalità:
 *   view          mappa di sola consultazione (particelle, confini, punto)
 *   pick-point    un clic sceglie il punto → evento Livewire "map-point-picked" { key, lat, lng }
 *   draw-polygon  i clic aggiungono vertici, i vertici si trascinano → "map-polygon-changed" { key, polygon }
 * Altri eventi: "map-parcel-selected" { key, id }, "map-outline-selected" { key, outline }.
 * Le coordinate dei dati sono sempre [lat, lng] come nell'originale (DemoPoint); GeoJSON resta [lng, lat].
 * Aggiornamenti dalla pagina: window "gestionale-map:update" con { key, ...config } (vedi applyUpdate).
 */
import workerUrl from 'maplibre-gl/dist/maplibre-gl-worker.mjs?worker&url';

// Stessa base cartografica di Gestionale/Trova (lib/map-basemap.ts): OpenFreeMap ricolorata, solo presentazione.
const BASEMAP = 'https://tiles.openfreemap.org/styles/dark';
const WATER = '#83d5f5';
const EMPTY = { type: 'FeatureCollection', features: [] };
const DEFAULT_CENTER = { lat: 45.481, lng: 9.188 };
const FILL = '#BDEBC8';
const INK = '#111111';

export function styleBasemap(map) {
    for (const layer of map.getStyle().layers) {
        const source = layer['source-layer'];
        const set = (property, value) => map.setPaintProperty(layer.id, property, value);
        if (layer.type === 'background') set('background-color', '#F1F3F1');
        if (layer.type === 'fill' && ['landcover', 'landuse', 'park'].includes(source || '')) set('fill-color', '#F1F3F1');
        if (layer.type === 'fill' && source === 'building') {
            set('fill-color', '#D7DADD');
            set('fill-outline-color', '#B8C0BE');
        }
        if (layer.type === 'fill' && source === 'water') {
            set('fill-color', WATER);
            set('fill-opacity', 1);
        }
        if (layer.type === 'line' && source === 'waterway') {
            set('line-color', WATER);
            set('line-opacity', 1);
            set('line-width', ['interpolate', ['linear'], ['zoom'], 8, 1, 14, 2, 18, 4]);
        }
        if (layer.type === 'symbol' && layer.layout?.['text-field']) {
            set('text-color', '#202725');
            set('text-halo-color', '#FFFFFF');
            set('text-halo-width', 2);
            set('text-opacity', 1);
        }
        if (layer.type === 'line' && source === 'transportation' && !/rail|ferry|aerialway/.test(layer.id)) {
            set('line-color', /casing/.test(layer.id) ? '#D7DADD' : '#FFFFFF');
            set('line-opacity', 1);
        }
    }
}

const reduced = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const isPoint = (p) => Array.isArray(p) && p.length === 2 && p.every((n) => Number.isFinite(n));
const same = (a, b) => Math.abs(a[0] - b[0]) < 1e-7 && Math.abs(a[1] - b[1]) < 1e-7;

/** Geodesic circle (64 lati) attorno a [lat, lng]; radius in metri. */
export function circleFeature(lat, lng, radiusM) {
    const R = 6371000, d = radiusM / R, lat1 = (lat * Math.PI) / 180, lng1 = (lng * Math.PI) / 180, ring = [];
    for (let i = 0; i <= 64; i++) {
        const b = (2 * Math.PI * i) / 64;
        const lat2 = Math.asin(Math.sin(lat1) * Math.cos(d) + Math.cos(lat1) * Math.sin(d) * Math.cos(b));
        const lng2 = lng1 + Math.atan2(Math.sin(b) * Math.sin(d) * Math.cos(lat1), Math.cos(d) - Math.sin(lat1) * Math.sin(lat2));
        ring.push([(lng2 * 180) / Math.PI, (lat2 * 180) / Math.PI]);
    }
    return { type: 'FeatureCollection', features: [{ type: 'Feature', properties: {}, geometry: { type: 'Polygon', coordinates: [ring] } }] };
}

/** Confine di una zona: poligono da 3 vertici, altrimenti una linea (prototype-map.tsx: Polygon / Polyline). */
export function boundaryFeatures(points) {
    const valid = (points || []).filter(isPoint);
    if (valid.length > 2) {
        const ring = valid.map(([lat, lng]) => [lng, lat]);
        return { type: 'FeatureCollection', features: [{ type: 'Feature', properties: { closed: true }, geometry: { type: 'Polygon', coordinates: [[...ring, ring[0]]] } }] };
    }
    if (valid.length === 2) {
        return { type: 'FeatureCollection', features: [{ type: 'Feature', properties: { closed: false }, geometry: { type: 'LineString', coordinates: valid.map(([lat, lng]) => [lng, lat]) } }] };
    }
    return EMPTY;
}

const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

document.addEventListener('alpine:init', () => {
    // Stato condiviso con i moduli della pagina (conteggio dei vertici, vertici correnti), per chiave di mappa
    window.Alpine.store('gmaps', {});

    window.Alpine.data('gestionaleMap', (config) => {
        // Fuori dallo stato reattivo di Alpine: MapLibre non funziona dentro un Proxy
        let map = null, maplibregl = null, ready = false, vertexMarkers = [], pointMarker = null, popup = null, timer = null, controller = 0;
        let vertexDragUntil = 0, onUpdate = null, onCommand = null, observer = null;
        let state = {
            mode: config.mode ?? 'view',
            point: config.point ?? null,
            polygon: config.polygon ?? [],
            parcels: config.parcels ?? EMPTY,
            buildings: config.buildings ?? EMPTY,
            zoneShape: config.zoneShape ?? EMPTY,
            boundaryShape: config.boundaryShape ?? null,
            areas: config.areas ?? EMPTY,
            selectedParcel: config.selectedParcel ?? null,
            selectedOutline: config.selectedOutline ?? null,
            outlines: config.outlines ?? null,
            outlineVersion: config.outlineVersion ?? '',
            fit: config.fit ?? [],
            centerRequest: config.centerRequest ?? 0,
        };
        let draft = state.mode === 'draw-polygon' ? (state.polygon ?? []).filter(isPoint).map((p) => [...p]) : [];
        let outlines = [], references = [], lastCenterRequest = state.centerRequest, lastDraftSeed = config.draftSeed ?? 0, lastOutlineVersion = state.outlineVersion, hoveredArea = null;

        return {
            loaded: false,
            notice: '',

            init() {
                const start = state.point ?? config.center ?? DEFAULT_CENTER;
                this.$store.gmaps[config.key] = { count: draft.length, polygon: draft.map((p) => [...p]) };
                import('maplibre-gl').then((module) => {
                    maplibregl = module;
                    if (this._destroyed) return;
                    maplibregl.setWorkerUrl(workerUrl);
                    this.mount(start);
                });

                onUpdate = (event) => {
                    if (event.detail?.key !== config.key) return;
                    this.applyUpdate(event.detail);
                };
                window.addEventListener('gestionale-map:update', onUpdate);
                onCommand = (event) => {
                    if (event.detail?.key !== config.key) return;
                    if (event.detail.command === 'undo') this.undo();
                    if (event.detail.command === 'center') this.fit(true);
                };
                window.addEventListener('gestionale-map:command', onCommand);
            },

            destroy() {
                this._destroyed = true;
                window.removeEventListener('gestionale-map:update', onUpdate);
                window.removeEventListener('gestionale-map:command', onCommand);
                clearTimeout(timer);
                observer?.disconnect();
                popup?.remove();
                map?.remove();
                map = null;
            },

            mount(start) {
                map = new maplibregl.Map({
                    container: this.$refs.canvas,
                    style: BASEMAP,
                    center: [start.lng, start.lat],
                    zoom: config.zoom ?? 13,
                    attributionControl: false,
                    cooperativeGestures: false,
                    locale: { 'NavigationControl.ZoomIn': 'Avvicina', 'NavigationControl.ZoomOut': 'Allontana', 'NavigationControl.ResetBearing': 'Orienta a nord' },
                });
                map.addControl(new maplibregl.NavigationControl({ showCompass: false }), 'top-right');
                map.addControl(new maplibregl.AttributionControl({ compact: true, customAttribution: 'OpenFreeMap · © OpenMapTiles · © OpenStreetMap contributors' }), 'bottom-right');
                // Il riquadro si ridimensiona con la pagina (come FitVisibleMap / invalidateSize)
                observer = new ResizeObserver(() => map?.resize());
                observer.observe(this.$refs.canvas);

                map.on('load', () => {
                    styleBasemap(map);
                    this.addLayers();
                    ready = true;
                    this.loaded = true;
                    this.render();
                    this.fit(false);
                    this.loadOutlines();
                });
                map.on('click', (event) => this.click(event));
                map.on('moveend', () => this.loadOutlines());
                map.getCanvas().classList.toggle('proto-map-drawing', state.mode === 'draw-polygon');
                if (state.mode === 'draw-polygon') map.doubleClickZoom.disable();
            },

            addLayers() {
                const add = (id, data, extra = {}) => map.addSource(id, { type: 'geojson', data, ...extra });
                add('gm-zone', EMPTY);
                add('gm-outlines', EMPTY);
                add('gm-references', EMPTY);
                add('gm-parcels', EMPTY);
                add('gm-buildings', EMPTY);
                add('gm-boundary', EMPTY);
                add('gm-circle', EMPTY);
                add('gm-areas', EMPTY, { promoteId: 'id' });

                // Quartieri e frazioni REKO da scegliere (public-neighborhood-picker.tsx)
                map.addLayer({ id: 'gm-areas-fill', type: 'fill', source: 'gm-areas', paint: { 'fill-color': FILL, 'fill-opacity': ['case', ['boolean', ['get', 'selected'], false], 0.3, ['boolean', ['feature-state', 'hover'], false], 0.2, 0.06] } });
                map.addLayer({ id: 'gm-areas-line', type: 'line', source: 'gm-areas', paint: { 'line-color': '#000000', 'line-width': ['case', ['boolean', ['get', 'selected'], false], 2.5, 1.2] } });
                // Quartiere REKO scelto: guida tratteggiata gialla, non interattiva
                map.addLayer({ id: 'gm-zone-fill', type: 'fill', source: 'gm-zone', paint: { 'fill-color': '#ffe600', 'fill-opacity': 0.035 } });
                map.addLayer({ id: 'gm-zone-line', type: 'line', source: 'gm-zone', paint: { 'line-color': '#ffe600', 'line-width': 2, 'line-dasharray': [3, 2] } });
                // Cartografia del Comune (municipal-map-layer.tsx)
                map.addLayer({ id: 'gm-outlines-fill', type: 'fill', source: 'gm-outlines', paint: { 'fill-color': FILL, 'fill-opacity': 0.3 } });
                map.addLayer({ id: 'gm-outlines-line', type: 'line', source: 'gm-outlines', paint: { 'line-color': INK, 'line-width': ['case', ['boolean', ['get', 'selected'], false], 2.5, 1.2] } });
                // Particelle del censimento
                map.addLayer({ id: 'gm-parcels-fill', type: 'fill', source: 'gm-parcels', paint: { 'fill-color': FILL, 'fill-opacity': 0.3 } });
                map.addLayer({ id: 'gm-parcels-line', type: 'line', source: 'gm-parcels', paint: { 'line-color': INK, 'line-width': ['case', ['boolean', ['get', 'selected'], false], 2.5, 1.2] } });
                map.addLayer({ id: 'gm-buildings-fill', type: 'fill', source: 'gm-buildings', paint: { 'fill-color': ['case', ['boolean', ['get', 'selected'], false], '#FFC93C', '#D7DADD'], 'fill-opacity': 1 } });
                map.addLayer({ id: 'gm-buildings-line', type: 'line', source: 'gm-buildings', paint: { 'line-color': INK, 'line-width': 1 } });
                map.addLayer({
                    id: 'gm-parcels-label', type: 'symbol', source: 'gm-parcels',
                    layout: { 'text-field': ['get', 'label'], 'text-font': ['Noto Sans Bold'], 'text-size': 13, 'text-allow-overlap': false },
                    paint: { 'text-color': '#202725', 'text-halo-color': '#FFFFFF', 'text-halo-width': 2 },
                });
                // Punti di riferimento («Diritti di superficie»): cerchi viola come .reko-surface-reference
                map.addLayer({ id: 'gm-references', type: 'circle', source: 'gm-references', paint: { 'circle-radius': 9, 'circle-color': '#7c3aed', 'circle-stroke-color': '#ffffff', 'circle-stroke-width': 2 } });
                // Confine della zona e raggio del punto
                map.addLayer({ id: 'gm-circle-fill', type: 'fill', source: 'gm-circle', paint: { 'fill-color': FILL, 'fill-opacity': 0.06 } });
                map.addLayer({ id: 'gm-circle-line', type: 'line', source: 'gm-circle', paint: { 'line-color': INK, 'line-width': 1.5, 'line-dasharray': [4, 3] } });
                map.addLayer({ id: 'gm-boundary-fill', type: 'fill', source: 'gm-boundary', filter: ['==', ['get', 'closed'], true], paint: { 'fill-color': FILL, 'fill-opacity': 0.06 } });
                map.addLayer({ id: 'gm-boundary-line', type: 'line', source: 'gm-boundary', paint: { 'line-color': INK, 'line-width': 2, 'line-dasharray': [3, 2] } });

                for (const layer of ['gm-outlines-fill', 'gm-parcels-fill', 'gm-buildings-fill', 'gm-references', 'gm-areas-fill']) {
                    map.on('mousemove', layer, (event) => this.hover(event, layer));
                    map.on('mouseleave', layer, () => this.unhover());
                }
            },

            // ---- Disegno dei dati

            render() {
                if (!ready) return;
                const selected = state.selectedParcel === null ? null : String(state.selectedParcel);
                const parcels = {
                    ...state.parcels,
                    features: (state.parcels?.features ?? []).map((f) => ({ ...f, properties: { ...f.properties, selected: selected !== null && String(f.properties?.id) === selected } })),
                };
                const buildings = {
                    ...state.buildings,
                    features: (state.buildings?.features ?? []).map((f) => ({ ...f, properties: { ...f.properties, selected: selected !== null && String(f.properties?.parcel) === selected } })),
                };
                map.getSource('gm-parcels').setData(parcels);
                map.getSource('gm-buildings').setData(buildings);
                map.getSource('gm-zone').setData(state.zoneShape ?? EMPTY);
                map.getSource('gm-references').setData({
                    type: 'FeatureCollection',
                    features: references.filter((o) => isPoint(o.point)).map((o) => ({ type: 'Feature', properties: { outline: JSON.stringify(o), title: o.title ?? '' }, geometry: { type: 'Point', coordinates: [o.point[1], o.point[0]] } })),
                });
                this.renderOutlines();
                const inDrawing = state.mode === 'draw-polygon';
                map.getSource('gm-boundary').setData(inDrawing ? boundaryFeatures(draft) : (state.boundaryShape ?? boundaryFeatures(state.polygon)));
                map.getSource('gm-areas').setData(state.areas ?? EMPTY);
                map.getSource('gm-circle').setData(state.point?.radius ? circleFeature(state.point.lat, state.point.lng, state.point.radius * 1000) : EMPTY);
                this.renderVertices();
                this.renderPoint();
            },

            renderOutlines() {
                if (!ready) return;
                const key = state.selectedOutline;
                map.getSource('gm-outlines').setData({
                    type: 'FeatureCollection',
                    features: outlines.map((o) => ({
                        type: 'Feature',
                        properties: { selected: key !== null && o.key === key, outline: JSON.stringify(o) },
                        geometry: { type: 'MultiPolygon', coordinates: (o.polygon ?? []).map((poly) => poly.map((ring) => ring.map(([lat, lng]) => [lng, lat]))) },
                    })),
                });
            },

            renderVertices() {
                vertexMarkers.forEach((marker) => marker.remove());
                vertexMarkers = [];
                if (state.mode !== 'draw-polygon') return;
                vertexMarkers = draft.map(([lat, lng], index) => {
                    const el = document.createElement('div');
                    el.className = 'crm-zone-vertex';
                    el.style.cssText = 'width:16px;height:16px;cursor:grab';
                    el.title = 'Vertice ' + (index + 1) + ' — trascina per modificare';
                    el.addEventListener('click', (event) => event.stopPropagation()); // un vertice non ne aggiunge un altro
                    const marker = new maplibregl.Marker({ element: el, draggable: true }).setLngLat([lng, lat]).addTo(map);
                    marker.on('dragstart', () => { vertexDragUntil = Infinity; });
                    marker.on('dragend', () => {
                        vertexDragUntil = Date.now() + 300; // un trascinamento può chiudersi con un clic: non è un nuovo vertice
                        const p = marker.getLngLat();
                        draft[index] = [p.lat, p.lng];
                        this.changed();
                    });
                    return marker;
                });
            },

            renderPoint() {
                pointMarker?.remove();
                pointMarker = null;
                if (!state.point || !isPoint([state.point.lat, state.point.lng])) return;
                const el = document.createElement('div');
                el.className = 'gmap-point';
                el.setAttribute('role', 'img');
                el.setAttribute('aria-label', 'Punto scelto');
                el.style.cssText = 'width:14px;height:14px;border-radius:50%;background:#FFC93C;border:2px solid #202725';
                pointMarker = new maplibregl.Marker({ element: el }).setLngLat([state.point.lng, state.point.lat]).addTo(map);
            },

            /** Aggiornamento dalla pagina: ogni chiave sostituisce quella dello stato. */
            applyUpdate(detail) {
                const { key, ...next } = detail;
                const wasDrawing = state.mode === 'draw-polygon';
                state = { ...state, ...next };
                if (state.mode === 'draw-polygon') {
                    // I vertici si rimettono ai valori iniziali solo all'apertura del disegno o con un nuovo "draftSeed":
                    // un aggiornamento qualsiasi della pagina non deve cancellare i punti già disegnati.
                    if (!wasDrawing || (next.draftSeed !== undefined && next.draftSeed !== lastDraftSeed)) {
                        draft = (state.polygon ?? []).filter(isPoint).map((p) => [...p]);
                        lastDraftSeed = state.draftSeed;
                    }
                    if (map) {
                        map.getCanvas().classList.add('proto-map-drawing');
                        map.doubleClickZoom.disable();
                    }
                    this.publish();
                } else if (wasDrawing || next.mode !== undefined) {
                    draft = [];
                    this.publish();
                    if (map) {
                        map.getCanvas().classList.remove('proto-map-drawing');
                        map.doubleClickZoom.enable();
                    }
                }
                if (next.outlineVersion !== undefined && next.outlineVersion !== lastOutlineVersion) {
                    lastOutlineVersion = next.outlineVersion;
                    outlines = [];
                    references = [];
                    this.loadOutlines();
                }
                this.render();
                // Come l'effetto di MapBehavior: si ricentra solo su richiesta esplicita
                if (next.centerRequest !== undefined && next.centerRequest !== lastCenterRequest) {
                    lastCenterRequest = next.centerRequest;
                    this.fit(true);
                }
            },

            // ---- Disegno della zona

            click(event) {
                const { lat, lng } = event.lngLat;
                if (state.mode === 'pick-point') {
                    state = { ...state, point: { ...(state.point ?? {}), lat, lng } };
                    this.renderPoint();
                    this.emit('map-point-picked', { lat, lng });
                    return;
                }
                if (state.mode === 'draw-polygon') {
                    if (Date.now() <= vertexDragUntil) return;
                    const point = [lat, lng];
                    if (draft.length >= (config.maxVertices ?? 200) || draft.some((other) => same(other, point))) return;
                    draft.push(point);
                    this.changed();
                    return;
                }
                const area = map.getLayer('gm-areas-fill') ? map.queryRenderedFeatures(event.point, { layers: ['gm-areas-fill'] })[0] : null;
                if (area) return this.emit('map-area-selected', { id: area.properties.id });
                // Selezione: prima i punti di riferimento, poi le particelle del censimento, poi la cartografia
                const hit = (layers) => map.queryRenderedFeatures(event.point, { layers: layers.filter((l) => map.getLayer(l)) })[0];
                const reference = hit(['gm-references']);
                if (reference) return this.emit('map-outline-selected', { outline: JSON.parse(reference.properties.outline) });
                const parcel = hit(['gm-parcels-fill', 'gm-buildings-fill']);
                if (parcel) {
                    const id = parcel.properties.id ?? parcel.properties.parcel;
                    return this.emit('map-parcel-selected', { id: Number.isFinite(Number(id)) ? Number(id) : id });
                }
                const outline = hit(['gm-outlines-fill']);
                if (outline) this.emit('map-outline-selected', { outline: JSON.parse(outline.properties.outline) });
            },

            undo() {
                draft.pop();
                this.changed();
            },

            /** I vertici sono cambiati: aggiorna disegno, store condiviso e pagina. */
            changed() {
                this.render();
                this.publish();
                this.emit('map-polygon-changed', { polygon: draft.map((p) => [...p]) });
            },

            publish() {
                this.$store.gmaps[config.key] = { count: draft.length, polygon: draft.map((p) => [...p]) };
            },

            emit(name, detail) {
                const payload = { key: config.key, ...detail };
                this.$el.dispatchEvent(new CustomEvent(name, { detail: payload, bubbles: true }));
                window.Livewire?.dispatch(name, payload);
            },

            // ---- Cartografia del Comune caricata sul riquadro visibile (municipal-map-layer.tsx)

            loadOutlines() {
                clearTimeout(timer);
                const method = state.outlines;
                if (!ready || !method || typeof this.$wire?.[method] !== 'function') return;
                // Leaflet zoom 16 = MapLibre zoom 15 (tessere da 256 e da 512 px)
                if (map.getZoom() < 15) {
                    outlines = [];
                    references = [];
                    this.notice = 'Avvicina la mappa per vedere i confini delle particelle.';
                    this.renderOutlines();
                    map.getSource('gm-references')?.setData(EMPTY);
                    return;
                }
                const token = ++controller;
                timer = setTimeout(async () => {
                    const b = map.getBounds();
                    this.notice = 'Caricamento fogli catastali…';
                    try {
                        const result = await this.$wire[method]([b.getSouth(), b.getWest(), b.getNorth(), b.getEast()].join(','));
                        if (token !== controller) return;
                        if (result?.error) throw new Error(result.error);
                        outlines = (result?.parcels ?? []).map((o) => ({ ...o, key: o.key }));
                        references = result?.references ?? [];
                        this.notice = result?.limited ? 'Avvicina la mappa per vedere tutte le particelle.' : '';
                    } catch (error) {
                        if (token !== controller) return;
                        outlines = [];
                        references = [];
                        this.notice = error?.message || 'Cartografia non disponibile.';
                    }
                    this.render();
                }, 200);
            },

            // ---- Posizione

            fit(animate) {
                if (!ready) return;
                const points = (state.fit ?? []).filter(isPoint);
                const poly = state.mode === 'draw-polygon' ? draft : state.polygon;
                const source = points.length ? points : (poly ?? []).filter(isPoint).length >= 3 ? poly : (state.point ? [[state.point.lat, state.point.lng]] : []);
                if (!source.length) return;
                const bounds = source.reduce((b, [lat, lng]) => b.extend([lng, lat]), new maplibregl.LngLatBounds([source[0][1], source[0][0]], [source[0][1], source[0][0]]));
                if (source.length === 1) {
                    map.easeTo({ center: [source[0][1], source[0][0]], zoom: Math.max(map.getZoom(), config.zoom ?? 13), duration: animate && !reduced() ? 250 : 0 });
                    return;
                }
                map.fitBounds(bounds, { padding: 32, maxZoom: 18, animate: animate && !reduced() });
            },

            // ---- Suggerimenti al passaggio del mouse (Tooltip di Leaflet)

            hover(event, layer) {
                const feature = event.features?.[0];
                if (!feature) return;
                map.getCanvas().style.cursor = state.mode === 'draw-polygon' ? 'crosshair' : 'pointer';
                let text = '';
                if (layer === 'gm-outlines-fill' || layer === 'gm-references') {
                    const o = JSON.parse(feature.properties.outline);
                    text = layer === 'gm-references'
                        ? 'Foglio ' + o.sheet + ' · Particella ' + o.parcel + ' · Diritto di superficie: punto della fonte, non perimetro'
                        : o.code + ' · Foglio ' + o.sheet + (o.development && o.development !== '0' ? ' · sviluppo ' + o.development : '') + ' · Particella ' + o.parcel + ' · Clicca per consultare';
                } else if (layer === 'gm-areas-fill') {
                    text = feature.properties.name;
                    if (hoveredArea !== feature.id) {
                        if (hoveredArea !== null) map.setFeatureState({ source: 'gm-areas', id: hoveredArea }, { hover: false });
                        hoveredArea = feature.id;
                        map.setFeatureState({ source: 'gm-areas', id: hoveredArea }, { hover: true });
                        this.$el.dispatchEvent(new CustomEvent('map-area-hover', { detail: { key: config.key, name: text }, bubbles: true }));
                    }
                } else if (feature.properties.tooltip) {
                    text = feature.properties.tooltip;
                }
                if (!text) return;
                popup ??= new maplibregl.Popup({ closeButton: false, closeOnClick: false, className: 'gmap-tooltip', offset: 8 });
                popup.setLngLat(event.lngLat).setHTML(escapeHtml(text)).addTo(map);
            },

            unhover() {
                if (map) map.getCanvas().style.cursor = state.mode === 'draw-polygon' ? 'crosshair' : '';
                if (hoveredArea !== null) {
                    map?.setFeatureState({ source: 'gm-areas', id: hoveredArea }, { hover: false });
                    hoveredArea = null;
                    this.$el.dispatchEvent(new CustomEvent('map-area-hover', { detail: { key: config.key, name: '' }, bubbles: true }));
                }
                popup?.remove();
            },
        };
    });
});
