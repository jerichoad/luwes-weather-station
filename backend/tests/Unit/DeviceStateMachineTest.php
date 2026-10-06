<?php

namespace Tests\Unit;

use App\Domain\Devices\DeviceStateMachine;
use App\Domain\Devices\DeviceStatus;
use PHPUnit\Framework\TestCase;

class DeviceStateMachineTest extends TestCase
{
    public function test_provisioned_to_active(): void
    {
        $this->assertTrue(DeviceStateMachine::canTransition(DeviceStatus::Provisioned, DeviceStatus::Active));
    }

    public function test_provisioned_to_maintenance_not_allowed(): void
    {
        $this->assertFalse(DeviceStateMachine::canTransition(DeviceStatus::Provisioned, DeviceStatus::Maintenance));
    }

    public function test_active_to_maintenance(): void
    {
        $this->assertTrue(DeviceStateMachine::canTransition(DeviceStatus::Active, DeviceStatus::Maintenance));
    }

    public function test_maintenance_to_active(): void
    {
        $this->assertTrue(DeviceStateMachine::canTransition(DeviceStatus::Maintenance, DeviceStatus::Active));
    }

    public function test_decommissioned_is_terminal(): void
    {
        $this->assertEmpty(DeviceStatus::Decommissioned->allowedTransitions());
        $this->assertFalse(DeviceStateMachine::canTransition(DeviceStatus::Decommissioned, DeviceStatus::Active));
    }

    public function test_all_can_reach_decommissioned(): void
    {
        $this->assertTrue(DeviceStateMachine::canTransition(DeviceStatus::Provisioned, DeviceStatus::Decommissioned));
        $this->assertTrue(DeviceStateMachine::canTransition(DeviceStatus::Active, DeviceStatus::Decommissioned));
        $this->assertTrue(DeviceStateMachine::canTransition(DeviceStatus::Maintenance, DeviceStatus::Decommissioned));
    }
}
