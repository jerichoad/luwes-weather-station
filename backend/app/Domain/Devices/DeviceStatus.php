<?php

namespace App\Domain\Devices;

enum DeviceStatus: string
{
    case Provisioned = 'provisioned';
    case Active = 'active';
    case Maintenance = 'maintenance';
    case Decommissioned = 'decommissioned';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Provisioned => [self::Active, self::Decommissioned],
            self::Active => [self::Maintenance, self::Decommissioned],
            self::Maintenance => [self::Active, self::Decommissioned],
            self::Decommissioned => [],
        };
    }

    /**
     * @return list<string>
     */
    public function allowedTransitionValues(): array
    {
        return array_map(fn (self $s) => $s->value, $this->allowedTransitions());
    }
}
