# Dati demo

## osm-cuneo-centro.json

Edifici (sagome) e numeri civici del centro di Cuneo, circa 1 km²
(bbox 44.3848, 7.5414 – 44.3938, 7.5540), scaricati il 5 ottobre 2026 da
OpenStreetMap con la Overpass API:

```
[out:json];(way["building"](44.3848,7.5414,44.3938,7.5540);
node["addr:housenumber"](44.3848,7.5414,44.3938,7.5540););out geom tags;
```

© OpenStreetMap contributors, licenza ODbL 1.0 — https://www.openstreetmap.org/copyright

Lo usa `Database\Seeders\DemoOsmCatalogSeeder`: sagome e indirizzi sono veri,
**particelle, subalterni, categorie e consistenze sono inventati**. Non sono dati catastali.
