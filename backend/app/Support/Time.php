<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;

final class Time
{
    public const WIB_OFFSET_S = 25200;

    public static function iso(DateTimeInterface|string|null $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::parse($value)->toIso8601ZuluString();
    }

    public static function parse(DateTimeInterface|string|int $value): CarbonImmutable
    {
        if (is_int($value)) {
            return CarbonImmutable::createFromTimestampUTC($value);
        }

        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->utc();
        }

        return CarbonImmutable::parse($value)->utc();
    }

    public static function epoch(DateTimeInterface|string $value): int
    {
        return self::parse($value)->getTimestamp();
    }

    public static function db(CarbonInterface|int $value): string
    {
        $c = is_int($value) ? CarbonImmutable::createFromTimestampUTC($value) : $value->utc();

        return $c->format('Y-m-d H:i:sP');
    }

    public static function hourStart(int $epoch): int
    {
        return intdiv($epoch, 3600) * 3600;
    }

    /** Awal hari WIB (00:00 WIB = 17:00 UTC hari sebelumnya), dalam epoch UTC. */
    public static function wibDayStart(int $epoch): int
    {
        return intdiv($epoch + self::WIB_OFFSET_S, 86400) * 86400 - self::WIB_OFFSET_S;
    }
}
