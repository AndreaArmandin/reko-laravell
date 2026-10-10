<?php

namespace App\Gestionale\Scouting;

use App\Gestionale\CommandRejected;

/**
 * Port of lib/crm/zone-boundary.ts validateZoneBoundary: 3 to 200 vertices as [latitude, longitude],
 * repeated clicks ignored, edges that never cross, a real area. (App\Trova\ZoneBoundary is the
 * stricter 64-point search zone of the public Trova and is not used here.)
 */
final class ZoneBoundary
{
    public const MIN_POINTS = 3;

    public const MAX_POINTS = 200;

    /**
     * @return list<array{0: float, 1: float}> open ring [lat, lng] without repeated points
     *
     * @throws CommandRejected with the gestionale message (400)
     */
    public static function validate(mixed $value): array
    {
        if (! is_array($value) || count($value) < self::MIN_POINTS || count($value) > self::MAX_POINTS) {
            throw new CommandRejected('Disegna da 3 a 200 vertici per delimitare la zona.');
        }
        foreach ($value as $p) {
            if (! is_array($p) || count($p) !== 2 || ! array_key_exists(0, $p) || ! array_key_exists(1, $p)
                || ! self::finite($p[0]) || ! self::finite($p[1]) || abs($p[0]) > 85 || abs($p[1]) > 180) {
                throw new CommandRejected('Coordinate della zona non valide.');
            }
        }

        // Repeated map clicks or a closing click on the first marker are not new vertices.
        $points = [];
        foreach (array_values($value) as $point) {
            $point = [(float) $point[0], (float) $point[1]];
            foreach ($points as $other) {
                if (abs($other[0] - $point[0]) < 1e-7 && abs($other[1] - $point[1]) < 1e-7) {
                    continue 2;
                }
            }
            $points[] = $point;
        }
        $n = count($points);
        if ($n < 3) {
            throw new CommandRejected('La zona richiede almeno tre punti diversi: clicca sulla mappa in tre posizioni separate.');
        }

        $cross = fn (array $a, array $b, array $c): float => ($b[0] - $a[0]) * ($c[1] - $a[1]) - ($b[1] - $a[1]) * ($c[0] - $a[0]);
        $on = fn (array $a, array $b, array $p): bool => $p[0] >= min($a[0], $b[0]) && $p[0] <= max($a[0], $b[0])
            && $p[1] >= min($a[1], $b[1]) && $p[1] <= max($a[1], $b[1]);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                if ($j === $i + 1 || ($i === 0 && $j === $n - 1)) {
                    continue;
                }
                [$a, $b, $c, $d] = [$points[$i], $points[($i + 1) % $n], $points[$j], $points[($j + 1) % $n]];
                $abC = $cross($a, $b, $c);
                $abD = $cross($a, $b, $d);
                $cdA = $cross($c, $d, $a);
                $cdB = $cross($c, $d, $b);
                if (($abC * $abD < 0 && $cdA * $cdB < 0) || ($abC == 0 && $on($a, $b, $c)) || ($abD == 0 && $on($a, $b, $d))
                    || ($cdA == 0 && $on($c, $d, $a)) || ($cdB == 0 && $on($c, $d, $b))) {
                    throw new CommandRejected('I confini si incrociano. Sposta i vertici prima di salvare.');
                }
            }
        }

        $area = 0.0;
        for ($i = 1; $i < $n - 1; $i++) {
            $area += $cross($points[0], $points[$i], $points[$i + 1]);
        }
        if (abs($area) < 1e-10) {
            throw new CommandRejected('La zona deve racchiudere un’area, non soltanto una linea.');
        }

        return $points;
    }

    /** GeoJSON Polygon ([lng, lat], ring closed) of a validated boundary. */
    public static function geoJson(array $points): array
    {
        $ring = array_map(fn (array $p) => [$p[1], $p[0]], $points);
        $ring[] = $ring[0];

        return ['type' => 'Polygon', 'coordinates' => [$ring]];
    }

    private static function finite(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value);
    }
}
