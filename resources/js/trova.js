import * as maplibregl from 'maplibre-gl'; // MapLibre 6: solo export con nome
import workerUrl from 'maplibre-gl/dist/maplibre-gl-worker.mjs?worker&url';

maplibregl.setWorkerUrl(workerUrl);

// Stessa base di Trova (lib/map-basemap.ts): OpenFreeMap ricolorata, solo presentazione.
const BASEMAP = 'https://tiles.openfreemap.org/styles/dark';
const WATER = '#83d5f5';
const EMPTY = { type: 'FeatureCollection', features: [] };

function styleBasemap(map) {
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

/** Geodesic circle as a 64-sided polygon around [lng, lat]. */
function circleFeature(lat, lng, radiusM) {
    const R = 6371000, d = radiusM / R, lat1 = (lat * Math.PI) / 180, lng1 = (lng * Math.PI) / 180, ring = [];
    for (let i = 0; i <= 64; i++) {
        const b = (2 * Math.PI * i) / 64;
        const lat2 = Math.asin(Math.sin(lat1) * Math.cos(d) + Math.cos(lat1) * Math.sin(d) * Math.cos(b));
        const lng2 = lng1 + Math.atan2(Math.sin(b) * Math.sin(d) * Math.cos(lat1), Math.cos(d) - Math.sin(lat1) * Math.sin(lat2));
        ring.push([(lng2 * 180) / Math.PI, (lat2 * 180) / Math.PI]);
    }
    return { type: 'FeatureCollection', features: [{ type: 'Feature', properties: { selected: true }, geometry: { type: 'Polygon', coordinates: [ring] } }] };
}

/** Zona disegnata: poligono solo da 3 vertici; "selected" quando è chiusa (search-zone-picker.tsx). */
function drawnFeature(points, selected = true) {
    if (!Array.isArray(points) || points.length < 3) return EMPTY;
    const coordinates = points.map(([lng, lat]) => [lng, lat]);
    return { type: 'FeatureCollection', features: [{ type: 'Feature', properties: { selected }, geometry: { type: 'Polygon', coordinates: [[...coordinates, coordinates[0]]] } }] };
}

/** Trova lib/crm/zone-boundary.ts: vertici distinti, lati che non si incrociano, un'area vera. Ritorna il messaggio o ''. */
function zoneBoundaryError(points) {
    if (points.length > 64) return 'Disegna la zona con al massimo 64 punti.';
    if (points.length < 3) return 'Disegna da 3 a 200 vertici per delimitare la zona.';
    if (new Set(points.map((p) => p.join(','))).size !== points.length) return 'I vertici della zona devono essere distinti.';
    const cross = (a, b, c) => (b[0] - a[0]) * (c[1] - a[1]) - (b[1] - a[1]) * (c[0] - a[0]);
    const on = (a, b, p) => p[0] >= Math.min(a[0], b[0]) && p[0] <= Math.max(a[0], b[0]) && p[1] >= Math.min(a[1], b[1]) && p[1] <= Math.max(a[1], b[1]);
    const n = points.length;
    for (let i = 0; i < n; i++) for (let j = i + 1; j < n; j++) {
        if (j === i + 1 || (i === 0 && j === n - 1)) continue;
        const a = points[i], b = points[(i + 1) % n], c = points[j], d = points[(j + 1) % n];
        const abC = cross(a, b, c), abD = cross(a, b, d), cdA = cross(c, d, a), cdB = cross(c, d, b);
        if ((abC * abD < 0 && cdA * cdB < 0) || (abC === 0 && on(a, b, c)) || (abD === 0 && on(a, b, d)) || (cdA === 0 && on(c, d, a)) || (cdB === 0 && on(c, d, b))) {
            return 'I confini si incrociano. Sposta i vertici prima di salvare.';
        }
    }
    const area = points.slice(1, -1).reduce((sum, p, i) => sum + cross(points[0], p, points[i + 2]), 0);
    return Math.abs(area) < 1e-10 ? 'La zona deve racchiudere un’area, non soltanto una linea.' : '';
}

const reduced = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

document.addEventListener('alpine:init', () => {
    // Risultato selezionato, condiviso fra schede e mappa (come selectedKey di Trova)
    window.Alpine.store('trova', { selected: '', hovered: '' });

    /**
     * Mappa di Trova (components/reko-map-3d.tsx), in versione Livewire.
     * config: { center: {lat,lng}, pick: bool, draw: bool, circle, polygon, zoneGeometry, points, shapes }
     */
    window.Alpine.data('trovaMap', (config) => {
    // Fuori dallo stato reattivo di Alpine: MapLibre non funziona dentro un Proxy
    let map = null, markers = [], vertices = [], pin = null, ready = false, onUpdate = null;
    // Vertici della zona che si sta disegnando: restano nel browser fino a «Chiudi zona»
    let draft = config.draw ? [...(config.polygon ?? [])] : [];
    let state = { circle: config.circle ?? null, polygon: config.polygon ?? [], zoneGeometry: config.zoneGeometry ?? EMPTY, points: config.points ?? [], shapes: config.shapes ?? EMPTY };

    return {
        three: false,
        loaded: false,
        editing: config.draw ? (config.polygon ?? []).length < 3 : false,
        count: config.draw ? (config.polygon ?? []).length : 0,
        error: '',
        note: config.draw ? '' : (config.pick ? 'Tocca la mappa per scegliere il punto. Poi regola il raggio.' : 'Esplora la mappa o seleziona un risultato.'),

        init() {
            const center = config.circle ?? config.center;
            map = new maplibregl.Map({
                container: this.$refs.canvas,
                style: BASEMAP,
                center: [center.lng, center.lat],
                zoom: 13,
                attributionControl: false,
                locale: { 'NavigationControl.ZoomIn': 'Avvicina', 'NavigationControl.ZoomOut': 'Allontana', 'NavigationControl.ResetBearing': 'Orienta a nord' },
            });
            map.addControl(new maplibregl.NavigationControl({ visualizePitch: true }), 'top-right');

            map.on('load', () => {
                styleBasemap(map);
                map.addSource('reko-openfreemap', { type: 'vector', url: 'https://tiles.openfreemap.org/planet' });
                map.addLayer({
                    id: 'reko-context-buildings', type: 'fill-extrusion', source: 'reko-openfreemap', 'source-layer': 'building', minzoom: 14,
                    layout: { visibility: 'none' },
                    paint: { 'fill-extrusion-color': '#D7DADD', 'fill-extrusion-height': ['coalesce', ['get', 'render_height'], 6], 'fill-extrusion-base': ['coalesce', ['get', 'render_min_height'], 0], 'fill-extrusion-opacity': 0.85 },
                });
                map.addSource('reko-area', { type: 'geojson', data: EMPTY });
                map.addLayer({ id: 'reko-area-fill', type: 'fill', source: 'reko-area', paint: { 'fill-color': '#BDEBC8', 'fill-opacity': ['case', ['boolean', ['get', 'selected'], false], 0.3, 0.06] } });
                map.addLayer({ id: 'reko-area-line', type: 'line', source: 'reko-area', paint: { 'line-color': '#000000', 'line-width': 1.5, 'line-dasharray': [4, 3] } });
                map.addSource('reko-result-parcels', { type: 'geojson', data: EMPTY, promoteId: 'id' });
                map.addLayer({ id: 'reko-result-parcel-fill', type: 'fill', source: 'reko-result-parcels', paint: { 'fill-color': '#FFC93C', 'fill-opacity': ['case', ['boolean', ['feature-state', 'active'], false], 0.55, 0.18] } });
                map.addLayer({ id: 'reko-result-parcel-line', type: 'line', source: 'reko-result-parcels', paint: { 'line-color': '#C78B00', 'line-width': ['case', ['boolean', ['feature-state', 'active'], false], 3, 2] } });
                ready = true;
                this.loaded = true;
                this.draw(true);
            });

            if (config.pick) {
                map.on('click', (e) => this.$wire.setPoint(e.lngLat.lat, e.lngLat.lng));
            }
            if (config.draw) {
                map.doubleClickZoom.disable(); // un doppio clic non deve aggiungere due vertici e zoomare
                map.on('click', (e) => this.addPoint(e.lngLat.lng, e.lngLat.lat));
            }

            // Livewire: nuovo cerchio, nuovi risultati
            onUpdate = (e) => {
                state = { ...state, ...e.detail };
                this.draw(e.detail.fit ?? true);
            };
            window.addEventListener('trova-map', onUpdate);

            // Selezione condivisa con le schede dei risultati
            let previous = '';
            window.Alpine.effect(() => {
                const id = this.$store.trova.selected || this.$store.trova.hovered;
                if (!ready) return;
                if (previous) map.setFeatureState({ source: 'reko-result-parcels', id: previous }, { active: false });
                if (id && state.shapes.features.some((f) => String(f.id) === String(id))) {
                    map.setFeatureState({ source: 'reko-result-parcels', id }, { active: true });
                }
                previous = id && state.shapes.features.some((f) => String(f.id) === String(id)) ? id : '';
                markers.forEach(({ el, ids }) => {
                    const on = ids.includes(String(id));
                    el.classList.toggle('is-selected', on);
                    el.setAttribute('aria-pressed', String(on));
                });
            });
            window.addEventListener('trova-fly', (e) => this.fly(String(e.detail.id)));
        },

        destroy() {
            window.removeEventListener('trova-map', onUpdate);
            map?.remove();
        },

        draw(fit) {
            if (!ready) return;
            const { circle, polygon, zoneGeometry, points, shapes } = state;
            const zone = { ...(zoneGeometry ?? EMPTY), features: (zoneGeometry?.features ?? []).map((f) => ({ ...f, properties: { ...f.properties, selected: true } })) };
            map.getSource('reko-area').setData(config.draw ? drawnFeature(draft, !this.editing) : (polygon.length ? drawnFeature(polygon) : (circle ? circleFeature(circle.lat, circle.lng, circle.radius) : zone)));
            this.drawVertices();
            map.getSource('reko-result-parcels').setData(shapes ?? EMPTY);

            pin?.remove();
            pin = null;
            if (circle) {
                const center = document.createElement('div');
                center.className = 'reko-3d-center';
                center.setAttribute('role', 'img');
                center.setAttribute('aria-label', 'Centro della ricerca');
                pin = new maplibregl.Marker({ element: center }).setLngLat([circle.lng, circle.lat]).addTo(map);
            }

            // Numeri: un solo bottone per punto, "+" quando più risultati coincidono
            markers.forEach(({ marker }) => marker.remove());
            const groups = new Map();
            for (const p of points) {
                const key = p.lat + ',' + p.lng;
                groups.set(key, [...(groups.get(key) ?? []), p]);
            }
            markers = [...groups.values()].map((group) => {
                const p = group[0], el = document.createElement('button');
                el.type = 'button';
                el.className = 'reko-3d-marker';
                el.textContent = String(p.number) + (group.length > 1 ? ' +' : '');
                el.setAttribute('aria-label', group.length > 1 ? group.length + ' risultati in questo punto' : p.label);
                el.setAttribute('aria-pressed', 'false');
                el.addEventListener('click', (e) => {
                    e.stopPropagation();
                    this.$store.trova.selected = String(p.id);
                    window.dispatchEvent(new CustomEvent('trova-open', { detail: { id: String(p.id) } }));
                });
                return { el, ids: group.map((g) => String(g.id)), marker: new maplibregl.Marker({ element: el }).setLngLat([p.lng, p.lat]).addTo(map) };
            });

            if (!fit) return;
            const animate = !reduced();
            if (circle) {
                const ring = circleFeature(circle.lat, circle.lng, circle.radius).features[0].geometry.coordinates[0];
                this.fitTo(ring, animate);
            } else if ((config.draw ? draft : polygon).length > 2) {
                this.fitTo(config.draw ? draft : polygon, animate);
            } else if (zoneGeometry?.features?.length) {
                const coords = [];
                const collect = (value) => {
                    if (!Array.isArray(value)) return;
                    if (typeof value[0] === 'number' && typeof value[1] === 'number') coords.push(value);
                    else value.forEach(collect);
                };
                zoneGeometry.features.forEach((feature) => collect(feature.geometry?.coordinates));
                if (coords.length) this.fitTo(coords, animate);
            } else if (points.length) {
                this.fitTo(points.map((p) => [p.lng, p.lat]), animate);
            }
        },

        // ---- Disegno della zona (search-zone-picker.tsx, modo "polygon")

        addPoint(lng, lat) {
            if (!this.editing) return;
            if (draft.length >= 64) {
                this.error = 'Hai raggiunto 64 punti. Chiudi la zona oppure annulla l’ultimo punto.';
                return;
            }
            draft.push([Math.round(lng * 1e6) / 1e6, Math.round(lat * 1e6) / 1e6]);
            this.count = draft.length;
            this.error = '';
            this.draw(false);
        },

        undoPoint() {
            draft.pop();
            this.count = draft.length;
            this.error = '';
            this.draw(false);
        },

        closeZone() {
            const error = zoneBoundaryError(draft);
            if (error) {
                this.error = error;
                return;
            }
            this.editing = false;
            this.error = '';
            this.draw(false);
            this.$wire.setPolygon(draft);
        },

        redraw() {
            // Come «Ridisegna» in Trova: i vertici restano, la zona torna da completare
            this.editing = true;
            this.error = '';
            this.draw(false);
            this.$wire.setPolygon([]);
        },

        drawVertices() {
            vertices.forEach((m) => m.remove());
            vertices = !config.draw ? [] : draft.map(([lng, lat], i) => {
                const el = document.createElement('button');
                el.type = 'button';
                el.className = 'reko-3d-marker';
                el.textContent = String(i + 1);
                el.setAttribute('aria-label', 'Punto ' + (i + 1) + ' del perimetro');
                el.addEventListener('click', (e) => e.stopPropagation()); // un vertice non aggiunge un altro punto
                return new maplibregl.Marker({ element: el }).setLngLat([lng, lat]).addTo(map);
            });
        },

        fitTo(coords, animate) {
            const bounds = coords.reduce((b, c) => b.extend(c), new maplibregl.LngLatBounds(coords[0], coords[0]));
            map.fitBounds(bounds, { padding: 48, maxZoom: 17, animate });
        },

        fly(id) {
            const p = state.points.find((point) => String(point.id) === id);
            if (p && ready) map.flyTo({ center: [p.lng, p.lat], zoom: Math.max(map.getZoom(), 16), duration: reduced() ? 0 : 250 });
        },

        // Strumenti come in Trova: Vista 3D, Ruota, Ripristina vista
        toggle3d() {
            this.three = !this.three;
            map.setLayoutProperty('reko-context-buildings', 'visibility', this.three ? 'visible' : 'none');
            map.easeTo({ pitch: this.three ? 60 : 0, duration: reduced() ? 0 : 400 });
        },
        rotate() {
            map.easeTo({ bearing: map.getBearing() + 45, duration: reduced() ? 0 : 400 });
        },
        reset() {
            this.three = false;
            map.setLayoutProperty('reko-context-buildings', 'visibility', 'none');
            map.easeTo({ pitch: 0, bearing: 0, duration: 0 });
            this.draw(true);
        },
    };
    });
});
