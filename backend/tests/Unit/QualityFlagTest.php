<?php

namespace Tests\Unit;

use App\Domain\Quality\QualityFlag;
use App\Domain\Quality\RangeValidator;
use PHPUnit\Framework\TestCase;

class QualityFlagTest extends TestCase
{
    public function test_error_code_detected(): void
    {
        $this->assertTrue(RangeValidator::isErrorCode(-999.0, [-999]));
        $this->assertFalse(RangeValidator::isErrorCode(25.0, [-999]));
    }

    public function test_out_of_range(): void
    {
        $this->assertTrue(RangeValidator::isOutOfRange(150.0, 0.0, 100.0));
        $this->assertFalse(RangeValidator::isOutOfRange(50.0, 0.0, 100.0));
        $this->assertTrue(RangeValidator::isOutOfRange(-0.1, 0.0, 100.0));
    }

    public function test_bitmask_combinations(): void
    {
        $flags = QualityFlag::OUT_OF_RANGE | QualityFlag::CLOCK_FUTURE;
        $this->assertTrue(QualityFlag::has($flags, QualityFlag::OUT_OF_RANGE));
        $this->assertTrue(QualityFlag::has($flags, QualityFlag::CLOCK_FUTURE));
        $this->assertFalse(QualityFlag::has($flags, QualityFlag::SENSOR_ERROR));
    }

    public function test_names(): void
    {
        $names = QualityFlag::names(QualityFlag::SENSOR_ERROR | QualityFlag::MAINTENANCE);
        $this->assertContains('SENSOR_ERROR', $names);
        $this->assertContains('MAINTENANCE', $names);
        $this->assertCount(2, $names);
    }
}
