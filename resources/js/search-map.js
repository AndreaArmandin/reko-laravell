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
        active: null, // id della particella accesa ora

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
                    // Clic: porta la riga in vista nella tabella
                    this.map.on('click', layer, (e) => {
                        document.querySelector(`[data-parcel="${e.features[0].id}"]`)
                            ?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    });
                }

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
            if (!bounds.isEmpty()) this.map.fitBounds(bounds, { padding: 40, maxZoom: 18 });
        },
    }));
});
