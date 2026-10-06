<?php

namespace App\Domain\Rain;

final class RainCalculator
{
    /**
     * @param  list<array{time:int, counter:float|int, seq:int}>  $points
     * @param  array{time:int, counter:float|int, seq:int}|null  $previous  bacaan valid terakhir sebelum bucket
     * @return list<float> curah hujan (mm) per titik, tidak pernah negatif
     */
    public static function deltasMm(array $points, ?array $previous, float $mmPerTip = 0.2): array
    {
        usort($points, fn ($a, $b) => $a['time'] <=> $b['time']);
        $prev = $previous;
        $out = [];

        foreach ($points as $p) {
            if ($prev === null) {
                $tips = 0.0;
            } elseif ($p['counter'] < $prev['counter'] || $p['seq'] < $prev['seq']) {
                // reset/reboot: counter mulai lagi dari 0, nilai sekarang adalah tip sejak boot
                $tips = (float) $p['counter'];
            } else {
                $tips = (float) ($p['counter'] - $prev['counter']);
            }

            if ($tips < 0) {
                $tips = 0.0;
            }

            $out[] = round($tips * $mmPerTip, 2);
            $prev = $p;
        }

        return $out;
    }
}
