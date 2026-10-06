<?php

namespace Tests\Unit;

use App\Domain\Rain\RainCalculator;
use PHPUnit\Framework\TestCase;

class RainCalculatorTest extends TestCase
{
    public function test_normal_increment(): void
    {
        $prev = ['time' => 0, 'counter' => 10, 'seq' => 100];
        $points = [['time' => 60, 'counter' => 15, 'seq' => 101]];

        $this->assertSame([1.0], RainCalculator::deltasMm($points, $prev));
    }

    public function test_counter_reset_tidak_menghasilkan_curah_hujan_minus(): void
    {
        $prev = ['time' => 0, 'counter' => 1043, 'seq' => 10432];
        $points = [['time' => 60, 'counter' => 5, 'seq' => 0]];

        $this->assertSame([1.0], RainCalculator::deltasMm($points, $prev));
    }

    public function test_restart_dua_kali_dalam_satu_jam(): void
    {
        $prev = ['time' => 0, 'counter' => 100, 'seq' => 500];
        $points = [
            ['time' => 60, 'counter' => 102, 'seq' => 501],
            ['time' => 120, 'counter' => 3, 'seq' => 0],
            ['time' => 180, 'counter' => 4, 'seq' => 1],
            ['time' => 240, 'counter' => 2, 'seq' => 0],
        ];

        $this->assertSame([0.4, 0.6, 0.2, 0.4], RainCalculator::deltasMm($points, $prev));
    }

    public function test_bacaan_pertama_tanpa_previous(): void
    {
        $points = [['time' => 60, 'counter' => 10, 'seq' => 0]];
        $this->assertSame([0.0], RainCalculator::deltasMm($points, null));
    }

    public function test_input_tidak_terurut_disort(): void
    {
        $prev = ['time' => 0, 'counter' => 0, 'seq' => 0];
        $points = [
            ['time' => 120, 'counter' => 5, 'seq' => 2],
            ['time' => 60, 'counter' => 3, 'seq' => 1],
        ];

        $deltas = RainCalculator::deltasMm($points, $prev);
        $this->assertSame([0.6, 0.4], $deltas);
    }

    public function test_hasil_tidak_pernah_negatif(): void
    {
        $prev = ['time' => 0, 'counter' => 5, 'seq' => 5];
        $points = [['time' => 60, 'counter' => 3, 'seq' => 6]];

        $deltas = RainCalculator::deltasMm($points, $prev);
        foreach ($deltas as $d) {
            $this->assertGreaterThanOrEqual(0, $d);
        }
    }
}
