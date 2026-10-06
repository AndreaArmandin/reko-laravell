<?php

namespace App\Trova;

/**
 * Hand-drawn search zone, as in Trova lib/crm/zone-boundary.ts and lib/search-area.ts:
 * 3 to 64 distinct vertices, edges that never cross, an actual area.
 * Points are [longitude, latitude]; the checks do not depend on the axis order.
 */
final class ZoneBoundary
{
    public const MAX_POINTS = 64;

    /**
     * @return list<array{0: float, 1: float}> the open ring (first point not repeated)
     *
     * @throws SearchException with Trova's message
     */
    public static function validate(mixed $value): array
    {
        if (is_array($value) && count($value) > self::MAX_POINTS) {
            throw new SearchException('Disegna la zona con al massimo 64 punti.');
        }
        if (! is_array($value) || count($value) < 3) {
            throw new SearchException('Disegna da 3 a 200 vertici per delimitare la zona.');
        }

        $points = [];
        foreach (array_values($value) as $p) {
            if (! is_array($p) || count($p) !== 2 || ! is_numeric($p[0] ?? null) || ! is_numeric($p[1] ?? null)
                || ! is_finite((float) $p[0]) || ! is_finite((float) $p[1])
                || abs((float) $p[0]) > 180 || abs((float) $p[1]) > 85) {
                throw new SearchException('Coordinate della zona non valide.');
            }
            $points[] = [(float) $p[0], (float) $p[1]];
        }
        if ($points[0] === $points[count($points) - 1]) {
            array_pop($points);
        }
        $keys = array_map(fn ($p) => $p[0].','.$p[1], $points);
        if (count($points) < 3 || count(array_unique($keys)) !== count($points)) {
            throw new SearchException('I vertici della zona devono essere distinti.');
        }

        $cross = fn (array $a, array $b, array $c) => ($b[0] - $a[0]) * ($c[1] - $a[1]) - ($b[1] - $a[1]) * ($c[0] - $a[0]);
        $on = fn (array $a, array $b, array $p) => $p[0] >= min($a[0], $b[0]) && $p[0] <= max($a[0], $b[0])
            && $p[1] >= min($a[1], $b[1]) && $p[1] <= max($a[1], $b[1]);
        $n = count($points);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                if ($j === $i + 1 || ($i === 0 && $j === $n - 1)) {
                    continue; // adjacent edges share a vertex
                }
                [$a, $b, $c, $d] = [$points[$i], $points[($i + 1) % $n], $points[$j], $points[($j + 1) % $n]];
                $abC = $cross($a, $b, $c);
                $abD = $cross($a, $b, $d);
                $cdA = $cross($c, $d, $a);
                $cdB = $cross($c, $d, $b);
                if (($abC * $abD < 0 && $cdA * $cdB < 0) || ($abC == 0 && $on($a, $b, $c)) || ($abD == 0 && $on($a, $b, $d))
                    || ($cdA == 0 && $on($c, $d, $a)) || ($cdB == 0 && $on($c, $d, $b))) {
                    throw new SearchException('I confini si incrociano. Sposta i vertici prima di salvare.');
                }
            }
        }

        $area = 0.0;
        for ($i = 1; $i < $n - 1; $i++) {
            $area += $cross($points[0], $points[$i], $points[$i + 1]);
        }
        if (abs($area) < 1e-10) {
            throw new SearchException('La zona deve racchiudere un’area, non soltanto una linea.');
        }

        return $points;
    }
}
