import * as maplibregl from 'maplibre-gl'; // MapLibre 6: solo export con nome
import workerUrl from 'maplibre-gl/dist/maplibre-gl-worker.mjs?worker&url';
import 'maplibre-gl/dist/maplibre-gl.css';

// Vite non copia il worker di MapLibre tra le dipendenze pre-compilate: indichiamo noi dove si trova (come Trova).
maplibregl.setWorkerUrl(workerUrl);

const EARTH_RADIUS_M = 6371000;
const CIRCLE_SIDES = 64;

/** Regular polygon approximating a geodesic circle (metres) around [lng, lat]. */
function circlePolygon(lng, lat, radiusM, sides = CIRCLE_SIDES) {
    const coords = [];
    const lat1 = (lat * Math.PI) / 180;
    const lng1 = (lng * Math.PI) / 180;
    const angDist = radiusM / EARTH_RADIUS_M;

    for (let i = 0; i <= sides; i++) {
        const bearing = (2 * Math.PI * i) / sides;
        const lat2 = Math.asin(
            Math.sin(lat1) * Math.cos(angDist) + Math.cos(lat1) * Math.sin(angDist) * Math.cos(bearing),
        );
        const lng2 =
            lng1 +
            Math.atan2(
                Math.sin(bearing) * Math.sin(angDist) * Math.cos(lat1),
                Math.cos(angDist) - Math.sin(lat1) * Math.sin(lat2),
            );
        coords.push([(lng2 * 180) / Math.PI, (lat2 * 180) / Math.PI]);
    }

    return {
        type: 'Feature',
        geometry: { type: 'Polygon', coordinates: [coords] },
        properties: {},
    };
}

// Mappa dei risultati di Trova: sagome gialle, puntine, cerchio di ricerca.
document.addEventListener('alpine:init', () => {
    window.Alpine.data('trovaMap', (initial, circle = {}) => ({
        map: null,
        features: initial, // ultimi risultati ricevuti, anche se la mappa non è ancora pronta
        active: null, // id della particella accesa ora
        lat: circle.lat ?? null,
        lng: circle.lng ?? null,
        radius: circle.radius ?? 500,
        marker: null,
        picking: false, // evita che il click sul risultato riposizioni il punto

        init() {
            this.map = new maplibregl.Map({
                container: this.$refs.map,
                style: 'https://tiles.openfreemap.org/styles/liberty',
                center: [7.5477, 44.3893],
                zoom: 14,
            });

            this.map.on('load', () => {
                this.map.addSource('results', { type: 'geojson', data: this.collection() });
                this.map.addSource('search-circle', {
                    type: 'geojson',
                    data: { type: 'FeatureCollection', features: [] },
                });

                // In MapLibre 'geometry-type' vale 'Polygon' anche per i MultiPolygon.
                this.map.addLayer({ id: 'shapes', type: 'fill', source: 'results',
                    filter: ['==', ['geometry-type'], 'Polygon'],
                    paint: {
                        'fill-color': ['case', ['boolean', ['feature-state', 'active'], false], '#f59e0b', '#ffc93c'],
                        'fill-opacity': ['case', ['boolean', ['feature-state', 'active'], false], 0.95, 0.75],
                    } });
                this.map.addLayer({ id: 'outlines', type: 'line', source: 'results',
                    filter: ['==', ['geometry-type'], 'Polygon'],
                    paint: {
                        'line-color': '#1b211d',
                        'line-width': ['case', ['boolean', ['feature-state', 'active'], false], 3, 1.5],
                    } });
                this.map.addLayer({ id: 'pins', type: 'circle', source: 'results',
                    filter: ['==', ['geometry-type'], 'Point'],
                    paint: {
                        'circle-radius': ['case', ['boolean', ['feature-state', 'active'], false], 9, 6],
                        'circle-color': ['case', ['boolean', ['feature-state', 'active'], false], '#f59e0b', '#ffc93c'],
                        'circle-stroke-color': '#1b211d',
                        'circle-stroke-width': 2,
                    } });

                this.map.addLayer({
                    id: 'search-circle-fill',
                    type: 'fill',
                    source: 'search-circle',
                    paint: { 'fill-color': '#3b82f6', 'fill-opacity': 0.12 },
                });
                this.map.addLayer({
                    id: 'search-circle-line',
                    type: 'line',
                    source: 'search-circle',
                    paint: { 'line-color': '#2563eb', 'line-width': 2, 'line-dasharray': [2, 2] },
                });

                // Mouse sopra una sagoma o una puntina: accendi sagoma e riga
                for (const layer of ['shapes', 'pins']) {
                    this.map.on('mousemove', layer, (e) => {
                        this.map.getCanvas().style.cursor = 'pointer';
                        this.highlight(e.features[0].id);
                    });
                    this.map.on('mouseleave', layer, () => {
                        this.map.getCanvas().style.cursor = '';
                        this.highlight(null);
                    });
                    // Clic sulla sagoma: porta la riga in vista (non sposta il punto)
                    this.map.on('click', layer, (e) => {
                        this.picking = true;
                        document.querySelector(`[data-parcel="${e.features[0].id}"]`)
                            ?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        queueMicrotask(() => { this.picking = false; });
                    });
                }

                // Clic sulla mappa (fuori dalle sagome): fissa il centro del raggio
                this.map.on('click', (e) => {
                    if (this.picking) return;
                    const features = this.map.queryRenderedFeatures(e.point, { layers: ['shapes', 'pins'] });
                    if (features.length > 0) return;
                    this.placePoint(e.lngLat.lat, e.lngLat.lng);
                });

                this.drawCircle();
                this.fit();
            });
        },

        // Chiamato quando arrivano nuovi risultati (nuova ricerca o cambio pagina)
        update(features) {
            this.highlight(null);
            this.features = features;
            const source = this.map.getSource('results');
            if (source) { // se la mappa non è ancora pronta, li userà il 'load' qui sopra
                source.setData(this.collection());
                this.fit();
            }
        },

        // Livewire aggiorna lat/lng/radius (slider, input, «Togli il punto»)
        setCircle({ lat = null, lng = null, radius = 500 } = {}) {
            this.lat = lat;
            this.lng = lng;
            this.radius = radius ?? 500;
            this.drawCircle();
        },

        placePoint(lat, lng) {
            // 6 decimali (~10 cm) bastano e restano validi per l'input
            lat = Math.round(lat * 1e6) / 1e6;
            lng = Math.round(lng * 1e6) / 1e6;
            this.lat = lat;
            this.lng = lng;
            this.drawCircle();
            if (this.$wire) {
                this.$wire.setPoint(lat, lng);
            }
        },

        drawCircle() {
            if (!this.map?.getSource('search-circle')) return;

            if (this.lat == null || this.lng == null) {
                this.map.getSource('search-circle').setData({ type: 'FeatureCollection', features: [] });
                if (this.marker) {
                    this.marker.remove();
                    this.marker = null;
                }
                return;
            }

            const feature = circlePolygon(this.lng, this.lat, Number(this.radius) || 500);
            this.map.getSource('search-circle').setData({ type: 'FeatureCollection', features: [feature] });

            if (!this.marker) {
                this.marker = new maplibregl.Marker({ color: '#2563eb' })
                    .setLngLat([this.lng, this.lat])
                    .addTo(this.map);
            } else {
                this.marker.setLngLat([this.lng, this.lat]);
            }
        },

        // Accende una particella (sagoma + riga); null = spegne tutto
        highlight(id) {
            const ready = !!this.map.getSource('results'); // prima del 'load' accendiamo solo la riga
            if (this.active !== null) {
                if (ready) this.map.setFeatureState({ source: 'results', id: this.active }, { active: false });
                document.querySelector(`[data-parcel="${this.active}"]`)?.classList.remove('trova-row-active');
            }
            this.active = id;
            if (id !== null) {
                if (ready) this.map.setFeatureState({ source: 'results', id }, { active: true });
                document.querySelector(`[data-parcel="${id}"]`)?.classList.add('trova-row-active');
            }
        },

        // Sposta e ingrandisce la mappa su una particella
        focus(id) {
            const feature = this.features.find((f) => f.id === id);
            if (!feature) return;
            const bounds = new maplibregl.LngLatBounds();
            const extend = (c) => (typeof c[0] === 'number' ? bounds.extend(c) : c.forEach(extend));
            extend(feature.geometry.coordinates);
            this.map.fitBounds(bounds, { padding: 80, maxZoom: 19 });
            this.highlight(id);
        },

        collection() {
            return { type: 'FeatureCollection', features: this.features };
        },

        fit() {
            const bounds = new maplibregl.LngLatBounds();
            const extend = (c) => (typeof c[0] === 'number' ? bounds.extend(c) : c.forEach(extend));
            this.features.forEach((f) => extend(f.geometry.coordinates));
            if (this.lat != null && this.lng != null) {
                const ring = circlePolygon(this.lng, this.lat, Number(this.radius) || 500).geometry.coordinates[0];
                ring.forEach((c) => bounds.extend(c));
            }
            if (!bounds.isEmpty()) this.map.fitBounds(bounds, { padding: 40, maxZoom: 18 });
        },
    }));
});
