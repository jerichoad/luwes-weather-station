<?php

namespace App\Domain\Aggregation;

final class CircularMean
{
    public const EPSILON = 1e-6;

    /**
     * @param  list<float|int>  $degrees
     * @return array{x:float, y:float, n:int}
     */
    public static function sums(array $degrees): array
    {
        $x = 0.0;
        $y = 0.0;
        $n = 0;

        foreach ($degrees as $d) {
            $rad = deg2rad((float) $d);
            $x += cos($rad);
            $y += sin($rad);
            $n++;
        }

        return ['x' => $x, 'y' => $y, 'n' => $n];
    }

    public static function fromSums(float $sumX, float $sumY, int $n): ?float
    {
        if ($n === 0) {
            return null;
        }

        $r = sqrt($sumX * $sumX + $sumY * $sumY) / $n;
        if ($r < self::EPSILON) {
            return null;
        }

        $deg = rad2deg(atan2($sumY, $sumX));
        $norm = round(fmod($deg + 360.0, 360.0), 2);

        if ($norm >= 360.0 || $norm == 0.0) {
            return 0.0;
        }

        return $norm;
    }

    /**
     * @param  list<float|int>  $degrees
     */
    public static function fromDegrees(array $degrees): ?float
    {
        $s = self::sums($degrees);

        return self::fromSums($s['x'], $s['y'], $s['n']);
    }
}
