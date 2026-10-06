<?php

namespace Tests\Unit;

use App\Domain\Calibration\CalibrationApplier;
use PHPUnit\Framework\TestCase;

class CalibrationApplierTest extends TestCase
{
    public function test_offset_only(): void
    {
        $cal = ['effective_from' => 0, 'scale_factor' => 1.0, 'offset_value' => -0.3];
        $this->assertEqualsWithDelta(27.1, CalibrationApplier::apply(27.4, $cal), 0.001);
    }

    public function test_scale_only(): void
    {
        $cal = ['effective_from' => 0, 'scale_factor' => 2.0, 'offset_value' => 0.0];
        $this->assertEqualsWithDelta(20.0, CalibrationApplier::apply(10.0, $cal), 0.001);
    }

    public function test_both(): void
    {
        $cal = ['effective_from' => 0, 'scale_factor' => 1.5, 'offset_value' => 2.0];
        $this->assertEqualsWithDelta(17.0, CalibrationApplier::apply(10.0, $cal), 0.001);
    }

    public function test_null_calibration_returns_identity(): void
    {
        $this->assertSame(27.4, CalibrationApplier::apply(27.4, null));
    }

    public function test_select_effective_at_time(): void
    {
        $cals = [
            ['effective_from' => 100, 'scale_factor' => 1.0, 'offset_value' => 0.0],
            ['effective_from' => 500, 'scale_factor' => 1.0, 'offset_value' => -0.3],
            ['effective_from' => 1000, 'scale_factor' => 1.0, 'offset_value' => -0.5],
        ];

        $selected = CalibrationApplier::select($cals, 600);
        $this->assertEquals(500, $selected['effective_from']);
        $this->assertEqualsWithDelta(-0.3, $selected['offset_value'], 0.001);
    }

    public function test_select_before_any_returns_null(): void
    {
        $cals = [['effective_from' => 1000, 'scale_factor' => 1.0, 'offset_value' => 0.0]];
        $this->assertNull(CalibrationApplier::select($cals, 500));
    }
}
