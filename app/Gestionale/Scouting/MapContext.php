<?php

namespace App\Gestionale\Scouting;

use Illuminate\Support\Str;

/**
 * Port of lib/crm/map-context.ts: read-only geographic warnings. Bounds describe the loaded
 * cartographic coverage, NOT an administrative border. Bounds are [minLat, minLng, maxLat, maxLng];
 * points are [lat, lng].
 */
final class MapContext
{
    public const NEIGHBORHOOD_NOTICE = 'Zone operative REKO: particelle intere assegnate solo con più del 50% della superficie nella zona. La linea tratteggiata è la guida di partenza; i confini delle particelle restano integri. Non sono confini amministrativi.';

    /** The four supported municipality catalogues from Gestionale's catalog-municipalities.ts. */
    private const PROPERTY_MUNICIPALITIES = [
        'F205' => ['code' => 'F205', 'municipality' => 'Milano', 'province' => 'MI'],
        'D205' => ['code' => 'D205', 'municipality' => 'Cuneo', 'province' => 'CN'],
        'D730' => ['code' => 'D730', 'municipality' => 'Forte dei Marmi', 'province' => 'LU'],
        'A453' => ['code' => 'A453', 'municipality' => 'Arzachena', 'province' => 'SS'],
    ];

    public static function validBounds(mixed $value): bool
    {
        if (! is_array($value) || count($value) !== 4) {
            return false;
        }
        $value = array_values($value);
        foreach ($value as $n) {
            if (! (is_int($n) || is_float($n)) || ! is_finite((float) $n)) {
                return false;
            }
        }

        return $value[0] >= -90 && $value[2] <= 90 && $value[1] >= -180 && $value[3] <= 180 && $value[0] < $value[2] && $value[1] < $value[3];
    }

    /** @return array{0: float, 1: float, 2: float, 3: float}|null */
    public static function boundsOfPoints(array $points): ?array
    {
        if ($points === []) {
            return null;
        }
        foreach ($points as $p) {
            if (! is_array($p) || count($p) !== 2 || ! is_numeric($p[0] ?? null) || ! is_numeric($p[1] ?? null) || abs((float) $p[0]) > 90 || abs((float) $p[1]) > 180) {
                return null;
            }
        }
        $lat = array_map(fn ($p) => (float) $p[0], $points);
        $lng = array_map(fn ($p) => (float) $p[1], $points);

        return [min($lat), min($lng), max($lat), max($lng)];
    }

    /**
     * @param  list<array{0: float, 1: float}>  $points
     * @param  list<array<string, mixed>>  $inventories  municipalities(): code, municipality, bounds
     */
    public static function coverageNotice(string $code, array $points, array $inventories): string
    {
        if ($points === []) {
            return '';
        }
        $bounds = self::boundsOfPoints($points);
        if ($bounds === null) {
            return 'Coordinate non valide: verifica il confine originale. Nessun dato è stato modificato.';
        }
        $inventory = collect($inventories)->firstWhere('code', $code);
        $coverage = $inventory['bounds'] ?? null;
        if (! self::validBounds($coverage)) {
            return 'La copertura cartografica del Comune non è disponibile: non è possibile controllare la coerenza dei confini.';
        }
        $disjoint = $bounds[2] < $coverage[0] || $bounds[0] > $coverage[2] || $bounds[3] < $coverage[1] || $bounds[1] > $coverage[3];
        if ($disjoint) {
            return "Il confine salvato non si sovrappone alla cartografia caricata per {$inventory['municipality']}. Verifica Comune e confini: i dati originali restano invariati.";
        }
        foreach ($points as [$lat, $lng]) {
            if ($lat < $coverage[0] || $lat > $coverage[2] || $lng < $coverage[1] || $lng > $coverage[3]) {
                return "Parte del confine è fuori dalla cartografia caricata per {$inventory['municipality']}. La copertura disponibile non coincide necessariamente con il confine amministrativo: verifica prima di salvare.";
            }
        }

        return ''; // Not evidence of cadastral or administrative correctness.
    }

    /** @return list<array{0: float, 1: float}> the two corners of the loaded coverage, or [] */
    public static function coverageFit(string $code, array $inventories): array
    {
        $bounds = collect($inventories)->firstWhere('code', $code)['bounds'] ?? null;

        return self::validBounds($bounds) ? [[$bounds[0], $bounds[1]], [$bounds[2], $bounds[3]]] : [];
    }

    /** Municipality in the original Gestionale's supported catalogue list, matched like municipalityForCity(). */
    public static function municipalityForCity(string $city): ?array
    {
        $normalize = static fn (string $value): string => mb_strtolower(trim(Str::ascii($value)), 'UTF-8');
        $needle = $normalize($city);
        if ($needle === '') {
            return null;
        }

        foreach (self::PROPERTY_MUNICIPALITIES as $municipality) {
            if ($normalize($municipality['municipality']) === $needle) {
                return $municipality;
            }
        }

        return null;
    }

    /** Same advisory shown below the selected point in properties.tsx. */
    public static function propertyPointNotice(string $city, array $point, array $inventories): string
    {
        $lat = $point['lat'] ?? null;
        $lng = $point['lng'] ?? null;
        if (! is_numeric($lat) || ! is_numeric($lng) || ! is_finite((float) $lat) || ! is_finite((float) $lng)
            || abs((float) $lat) > 90 || abs((float) $lng) > 180) {
            return 'Coordinate non valide: scegli un punto sulla mappa.';
        }

        $town = self::municipalityForCity($city);
        if ($town === null) {
            return 'Per questo Comune non è disponibile un controllo geografico automatico. Verifica la posizione con la fonte originale.';
        }

        $inventory = collect($inventories)->firstWhere('code', $town['code']);
        $bounds = $inventory['bounds'] ?? null;
        if (! self::validBounds($bounds)) {
            return 'Cartografia di '.(string) $town['municipality'].' non disponibile: la posizione non può essere verificata.';
        }

        if ((float) $lat < $bounds[0] || (float) $lat > $bounds[2] || (float) $lng < $bounds[1] || (float) $lng > $bounds[3]) {
            return 'Il punto è fuori dalla cartografia caricata per '.(string) $town['municipality'].'. Verifica Comune e posizione: non viene spostato automaticamente.';
        }

        return '';
    }

    /** Match propertyPointSaveError(): unchanged points survive edits; new points need an explicit map pick. */
    public static function propertyPointSaveError(string $city, array $point, array $inventories, ?array $original, bool $explicitlyChosen): string
    {
        $lat = is_numeric($point['lat'] ?? null) ? (float) $point['lat'] : NAN;
        $lng = is_numeric($point['lng'] ?? null) ? (float) $point['lng'] : NAN;
        if ($original !== null && is_numeric($original['lat'] ?? null) && is_numeric($original['lng'] ?? null)
            && $lat === (float) $original['lat'] && $lng === (float) $original['lng']) {
            return '';
        }
        if ($original === null && ! $explicitlyChosen) {
            return 'Indica esplicitamente la posizione sulla mappa. Il punto iniziale non viene attribuito alla nuova scheda.';
        }

        $notice = self::propertyPointNotice($city, ['lat' => $lat, 'lng' => $lng], $inventories);
        if (! is_finite($lat) || ! is_finite($lng) || abs($lat) > 90 || abs($lng) > 180) {
            return $notice;
        }

        return self::municipalityForCity($city) !== null ? $notice : '';
    }
}
