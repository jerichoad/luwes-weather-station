<?php

namespace App\Domain\Quality;

final class RangeValidator
{
    /**
     * @param  list<float|int>  $errorCodes
     */
    public static function isErrorCode(float $rawValue, array $errorCodes): bool
    {
        foreach ($errorCodes as $code) {
            if (abs($rawValue - (float) $code) < 1e-6) {
                return true;
            }
        }

        return false;
    }

    public static function isOutOfRange(float $value, float $min, float $max): bool
    {
        return $value < $min || $value > $max;
    }
}
