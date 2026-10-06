<?php

namespace App\Domain\Aggregation;

use App\Domain\Rain\RainCalculator;

final class BucketAggregator
{
    /**
     * Hitung statistik satu bucket jam.
     *
     * @param  list<array{time:int, value:?float, flags:int, seq?:?int}>  $readings
     * @param  array{time:int, counter:float, seq:int}|null  $previousCounter  khusus counter
     * @return array{sample_count:int, good_count:int, avg:?float, min:?float, max:?float, sum:?float, sum_x:?float, sum_y:?float}
     */
    public static function aggregate(string $kind, array $readings, ?array $previousCounter = null, float $counterFactor = 0.2): array
    {
        $good = array_values(array_filter($readings, fn ($r) => $r['flags'] === 0 && $r['value'] !== null));
        $values = array_map(fn ($r) => (float) $r['value'], $good);

        $out = [
            'sample_count' => count($readings),
            'good_count' => count($good),
            'avg' => null, 'min' => null, 'max' => null, 'sum' => null, 'sum_x' => null, 'sum_y' => null,
        ];

        if ($values === []) {
            if ($kind === 'counter') {
                $out['sum'] = 0.0;
            }

            return $out;
        }

        $out['min'] = min($values);
        $out['max'] = max($values);

        switch ($kind) {
            case 'angle':
                $s = CircularMean::sums($values);
                $out['sum_x'] = $s['x'];
                $out['sum_y'] = $s['y'];
                $out['avg'] = CircularMean::fromSums($s['x'], $s['y'], $s['n']);
                break;

            case 'counter':
                $points = array_map(fn ($r) => [
                    'time' => $r['time'],
                    'counter' => (float) $r['value'],
                    'seq' => (int) ($r['seq'] ?? 0),
                ], $good);
                $out['sum'] = round(array_sum(RainCalculator::deltasMm($points, $previousCounter, $counterFactor)), 2);
                $out['min'] = null;
                $out['max'] = null;
                break;

            default:
                $out['avg'] = array_sum($values) / count($values);
        }

        return $out;
    }

    /**
     * Turunkan agregat harian dari row 1h.
     *
     * @param  list<array{sample_count:int, good_count:int, avg:?float, min:?float, max:?float, sum:?float, sum_x:?float, sum_y:?float}>  $hours
     * @return array{sample_count:int, good_count:int, avg:?float, min:?float, max:?float, sum:?float, sum_x:?float, sum_y:?float}
     */
    public static function rollup(string $kind, array $hours): array
    {
        $out = [
            'sample_count' => array_sum(array_column($hours, 'sample_count')),
            'good_count' => array_sum(array_column($hours, 'good_count')),
            'avg' => null, 'min' => null, 'max' => null, 'sum' => null, 'sum_x' => null, 'sum_y' => null,
        ];

        $mins = array_filter(array_column($hours, 'min'), fn ($v) => $v !== null);
        $maxs = array_filter(array_column($hours, 'max'), fn ($v) => $v !== null);
        $out['min'] = $mins ? min($mins) : null;
        $out['max'] = $maxs ? max($maxs) : null;

        if ($kind === 'counter') {
            $out['sum'] = round(array_sum(array_map(fn ($h) => (float) ($h['sum'] ?? 0), $hours)), 2);

            return $out;
        }

        if ($kind === 'angle') {
            $x = array_sum(array_map(fn ($h) => (float) ($h['sum_x'] ?? 0), $hours));
            $y = array_sum(array_map(fn ($h) => (float) ($h['sum_y'] ?? 0), $hours));
            $out['sum_x'] = $x;
            $out['sum_y'] = $y;
            $out['avg'] = CircularMean::fromSums($x, $y, $out['good_count']);

            return $out;
        }

        $weighted = 0.0;
        $weight = 0;
        foreach ($hours as $h) {
            if ($h['avg'] !== null && $h['good_count'] > 0) {
                $weighted += $h['avg'] * $h['good_count'];
                $weight += $h['good_count'];
            }
        }
        $out['avg'] = $weight > 0 ? $weighted / $weight : null;

        return $out;
    }
}
