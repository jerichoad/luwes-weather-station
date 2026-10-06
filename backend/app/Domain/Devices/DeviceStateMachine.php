<?php

namespace App\Domain\Devices;

final class DeviceStateMachine
{
    public static function canTransition(DeviceStatus $from, DeviceStatus $to): bool
    {
        return in_array($to, $from->allowedTransitions(), true);
    }
}
