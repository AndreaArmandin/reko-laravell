<?php

namespace App\Trova;

/**
 * Flat SVG drawing of the zones in a GeoJSON file, for the admin check before importing.
 * Plain equirectangular projection (longitude scaled by cos(latitude)): good enough to see shape and overlaps.
 */
final class ZonePreview
{
    private const WIDTH = 640;

    /**
     * @return array{viewBox: string, zones: list<array{name: string, d: string}>}|null null when nothing can be drawn
     */
    public static function fromFile(string $path, string $nameProperty = 'name'): ?array
    {
        $data = json_decode((string) @file_get_contents($path), true);
        $features = is_array($data) && is_array($data['features'] ?? null) ? $data['features'] : [];

        $polygons = [];
        foreach ($features as $feature) {
            $geometry = is_array($feature) ? ($feature['geometry'] ?? null) : null;
            $coordinates = is_array($geometry) ? ($geometry['coordinates'] ?? null) : null;
            if (! is_array($coordinates) || ! in_array($geometry['type'] ?? null, ['Polygon', 'MultiPolygon'], true)) {
                continue;
            }
            $name = $feature['properties'][$nameProperty] ?? '';
            $polygons[] = [is_string($name) ? $name : '', $geometry['type'] === 'Polygon' ? [$coordinates] : $coordinates];
        }

        $lngs = $lats = [];
        foreach ($polygons as [, $parts]) {
            foreach ($parts as $rings) {
                foreach ($rings[0] ?? [] as $point) {
                    if (is_array($point) && is_numeric($point[0] ?? null) && is_numeric($point[1] ?? null)) {
                        $lngs[] = (float) $point[0];
                        $lats[] = (float) $point[1];
                    }
                }
            }
        }
        if ($lngs === [] || $lats === []) {
            return null;
        }

        [$minLng, $maxLng, $minLat, $maxLat] = [min($lngs), max($lngs), min($lats), max($lats)];
        $scale = cos(deg2rad(($minLat + $maxLat) / 2));
        $spanX = max(($maxLng - $minLng) * $scale, 1e-9);
        $spanY = max($maxLat - $minLat, 1e-9);
        $factor = self::WIDTH / $spanX;
        $height = max(1, (int) round($spanY * $factor));
        $project = fn (array $p) => round(((float) $p[0] - $minLng) * $scale * $factor, 1).' '.round(($maxLat - (float) $p[1]) * $factor, 1);

        $zones = [];
        foreach ($polygons as [$name, $parts]) {
            $d = '';
            foreach ($parts as $rings) {
                foreach ($rings as $ring) {
                    $points = array_values(array_filter(is_array($ring) ? $ring : [], fn ($p) => is_array($p) && is_numeric($p[0] ?? null) && is_numeric($p[1] ?? null)));
                    if (count($points) > 2) {
                        $d .= 'M'.implode('L', array_map($project, $points)).'Z';
                    }
                }
            }
            if ($d !== '') {
                $zones[] = ['name' => $name, 'd' => $d];
            }
        }

        return $zones === [] ? null : ['viewBox' => '0 0 '.self::WIDTH.' '.$height, 'zones' => $zones];
    }
}
