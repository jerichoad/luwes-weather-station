<?php

namespace Tests\Unit;

use App\Domain\Ingestion\PacketNormalizer;
use PHPUnit\Framework\TestCase;

class PacketNormalizerTest extends TestCase
{
    private function normalizer(): PacketNormalizer
    {
        return new PacketNormalizer(300, 1577836800);
    }

    public function test_ts_sebelum_2020_ditolak(): void
    {
        $items = [['index' => 0, 'ts' => 1000000, 'seq' => 0, 'readings' => []]];
        $result = $this->normalizer()->normalize($items, 1757308800);
        $this->assertCount(1, $result['rejected']);
        $this->assertCount(0, $result['packets']);
    }

    public function test_ts_lama_diterima(): void
    {
        $items = [['index' => 0, 'ts' => 1757308800, 'seq' => 0, 'readings' => []]]; // Sept 2025
        $result = $this->normalizer()->normalize($items, time());
        $this->assertCount(1, $result['packets']);
        $this->assertCount(0, $result['rejected']);
    }

    public function test_dedup_ts_sama_dalam_batch(): void
    {
        $items = [
            ['index' => 0, 'ts' => 1757308800, 'seq' => 0, 'readings' => []],
            ['index' => 1, 'ts' => 1757308800, 'seq' => 1, 'readings' => []],
        ];
        $result = $this->normalizer()->normalize($items, time());
        $this->assertCount(1, $result['packets']);
        $this->assertCount(1, $result['duplicates']);
    }

    public function test_batch_disort_berdasar_ts(): void
    {
        $items = [
            ['index' => 0, 'ts' => 1757308860, 'seq' => 1, 'readings' => []],
            ['index' => 1, 'ts' => 1757308800, 'seq' => 0, 'readings' => []],
        ];
        $result = $this->normalizer()->normalize($items, time());
        $this->assertCount(2, $result['packets']);
        $this->assertEquals(1757308800, $result['packets'][0]['ts']);
    }

    public function test_clock_future_flag(): void
    {
        $now = time();
        $items = [['index' => 0, 'ts' => $now + 600, 'seq' => 0, 'readings' => []]];
        $result = $this->normalizer()->normalize($items, $now);
        $this->assertTrue(($result['packets'][0]['flags'] & 4) === 4);
    }

    public function test_duplicate_sensor_in_packet(): void
    {
        $items = [['index' => 0, 'ts' => 1757308800, 'seq' => 0, 'readings' => [
            ['s' => 'temp_air', 'v' => 25.0],
            ['s' => 'temp_air', 'v' => 26.0],
        ]]];
        $result = $this->normalizer()->normalize($items, time());
        $this->assertCount(1, $result['packets'][0]['readings']);
        $this->assertEquals(25.0, $result['packets'][0]['readings'][0]['v']);
    }
}
