<?php

namespace App\Domain\Quality;

final class QualityFlag
{
    public const OUT_OF_RANGE = 1;

    public const SENSOR_ERROR = 2;

    public const CLOCK_FUTURE = 4;

    public const MAINTENANCE = 8;

    public static function has(int $flags, int $flag): bool
    {
        return ($flags & $flag) === $flag;
    }

    /**
     * @return list<string>
     */
    public static function names(int $flags): array
    {
        $out = [];
        if (self::has($flags, self::OUT_OF_RANGE)) {
            $out[] = 'OUT_OF_RANGE';
        }
        if (self::has($flags, self::SENSOR_ERROR)) {
            $out[] = 'SENSOR_ERROR';
        }
        if (self::has($flags, self::CLOCK_FUTURE)) {
            $out[] = 'CLOCK_FUTURE';
        }
        if (self::has($flags, self::MAINTENANCE)) {
            $out[] = 'MAINTENANCE';
        }

        return $out;
    }
}
