<?php

namespace App\Domain\Devices;

use DateTimeInterface;

final class Connectivity
{
    public const ONLINE = 'online';

    public const STALE = 'stale';

    public const OFFLINE = 'offline';

    public const NEVER = 'never';

    public static function fromLastSeen(?DateTimeInterface $lastSeen, DateTimeInterface $now, int $onlineS = 300, int $offlineS = 900): string
    {
        if ($lastSeen === null) {
            return self::NEVER;
        }

        $silent = $now->getTimestamp() - $lastSeen->getTimestamp();

        return match (true) {
            $silent <= $onlineS => self::ONLINE,
            $silent <= $offlineS => self::STALE,
            default => self::OFFLINE,
        };
    }
}
