import * as maplibregl from 'maplibre-gl'; // MapLibre 6: solo export con nome
import workerUrl from 'maplibre-gl/dist/maplibre-gl-worker.mjs?worker&url';
import 'maplibre-gl/dist/maplibre-gl.css';

// Vite non copia il worker di MapLibre tra le dipendenze pre-compilate: indichiamo noi dove si trova (come Trova).
maplibregl.setWorkerUrl(workerUrl);

// Mappa dei risultati di Trova: sagome gialle, puntine per le particelle senza sagoma.
document.addEventListener('alpine:init', () => {
    window.Alpine.data('trovaMap', (initial) => ({
        map: null,
        features: initial, // ultimi risultati ricevuti, anche se la mappa non è ancora pronta

        init() {
            this.map = new maplibregl.Map({
                container: this.$refs.map,
                style: 'https://tiles.openfreemap.org/styles/liberty',
                center: [7.5477, 44.3893],
                zoom: 14,
            });

            this.map.on('load', () => {
                this.map.addSource('results', { type: 'geojson', data: this.collection() });

                // In MapLibre 'geometry-type' vale 'Polygon' anche per i MultiPolygon.
                this.map.addLayer({ id: 'shapes', type: 'fill', source: 'results',
                    filter: ['==', ['geometry-type'], 'Polygon'],
                    paint: { 'fill-color': '#ffc93c', 'fill-opacity': 0.75 } });
                this.map.addLayer({ id: 'outlines', type: 'line', source: 'results',
                    filter: ['==', ['geometry-type'], 'Polygon'],
                    paint: { 'line-color': '#1b211d', 'line-width': 1.5 } });
                this.map.addLayer({ id: 'pins', type: 'circle', source: 'results',
                    filter: ['==', ['geometry-type'], 'Point'],
                    paint: { 'circle-radius': 6, 'circle-color': '#ffc93c', 'circle-stroke-color': '#1b211d', 'circle-stroke-width': 2 } });

                this.fit();
            });
        },

        // Chiamato quando arrivano nuovi risultati (nuova ricerca o cambio pagina)
        update(features) {
            this.features = features;
            const source = this.map.getSource('results');
            if (source) { // se la mappa non è ancora pronta, li userà il 'load' qui sopra
                source.setData(this.collection());
                this.fit();
            }
        },

        collection() {
            return { type: 'FeatureCollection', features: this.features };
        },

        fit() {
            const bounds = new maplibregl.LngLatBounds();
            const extend = (c) => (typeof c[0] === 'number' ? bounds.extend(c) : c.forEach(extend));
            this.features.forEach((f) => extend(f.geometry.coordinates));
            if (!bounds.isEmpty()) this.map.fitBounds(bounds, { padding: 40, maxZoom: 18 });
        },
    }));
});
