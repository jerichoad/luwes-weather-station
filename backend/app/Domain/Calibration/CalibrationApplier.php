<?php

namespace App\Domain\Calibration;

final class CalibrationApplier
{
    /**
     * Pilih kalibrasi yang berlaku pada waktu $t: effective_from terbesar yang <= $t.
     *
     * @param  list<array{effective_from:int, scale_factor:float, offset_value:float}>  $calibrations
     * @return array{effective_from:int, scale_factor:float, offset_value:float}|null
     */
    public static function select(array $calibrations, int $t): ?array
    {
        $chosen = null;

        foreach ($calibrations as $cal) {
            if ($cal['effective_from'] <= $t && ($chosen === null || $cal['effective_from'] > $chosen['effective_from'])) {
                $chosen = $cal;
            }
        }

        return $chosen;
    }

    /**
     * @param  array{scale_factor:float, offset_value:float}|null  $calibration
     */
    public static function apply(float $raw, ?array $calibration): float
    {
        if ($calibration === null) {
            return $raw;
        }

        return $raw * (float) $calibration['scale_factor'] + (float) $calibration['offset_value'];
    }

    /**
     * @param  list<array{effective_from:int, scale_factor:float, offset_value:float}>  $calibrations
     */
    public static function applyAt(float $raw, array $calibrations, int $t): float
    {
        return self::apply($raw, self::select($calibrations, $t));
    }
}
