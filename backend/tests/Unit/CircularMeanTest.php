<?php

namespace Tests\Unit;

use App\Domain\Aggregation\CircularMean;
use PHPUnit\Framework\TestCase;

class CircularMeanTest extends TestCase
{
    public function test_350_and_10_gives_0(): void
    {
        $result = CircularMean::fromDegrees([350.0, 10.0]);
        $this->assertEqualsWithDelta(0.0, $result, 0.1);
    }

    public function test_90_and_270_gives_null(): void
    {
        $this->assertNull(CircularMean::fromDegrees([90.0, 270.0]));
    }

    public function test_single_value(): void
    {
        $this->assertEqualsWithDelta(180.0, CircularMean::fromDegrees([180.0]), 0.01);
    }

    public function test_same_values(): void
    {
        $this->assertEqualsWithDelta(45.0, CircularMean::fromDegrees([45.0, 45.0, 45.0]), 0.01);
    }

    public function test_empty_returns_null(): void
    {
        $this->assertNull(CircularMean::fromDegrees([]));
    }
}
