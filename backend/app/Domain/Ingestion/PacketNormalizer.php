<?php

namespace App\Domain\Ingestion;

use App\Domain\Quality\QualityFlag;
use App\Support\ErrorCode;

final class PacketNormalizer
{
    public function __construct(
        private readonly int $futureToleranceS = 300,
        private readonly int $minTs = 1577836800,
    ) {}

    /**
     * Input: item yang sudah lolos validasi schema, masing-masing punya 'index'.
     *
     * @param  list<array{index:int, ts:int, seq:int, battery_v?:float|null, rssi?:int|null, readings:list<array{s:string, v:float|int}>}>  $items
     * @return array{
     *   packets: list<array{index:int, ts:int, seq:int, battery_v:?float, rssi:?int, flags:int, readings:list<array{s:string, v:float}>, warnings:list<array{code:string, message:string}>}>,
     *   duplicates: list<array{index:int, ts:int, seq:int}>,
     *   rejected: list<array{index:int, ts:int, seq:int, code:string, message:string}>
     * }
     */
    public function normalize(array $items, int $serverNow): array
    {
        $packets = [];
        $duplicates = [];
        $rejected = [];

        usort($items, fn ($a, $b) => [$a['ts'], $a['index']] <=> [$b['ts'], $b['index']]);

        $seenTs = [];

        foreach ($items as $item) {
            $ts = (int) $item['ts'];
            $seq = (int) $item['seq'];

            if ($ts < $this->minTs) {
                $rejected[] = [
                    'index' => $item['index'], 'ts' => $ts, 'seq' => $seq,
                    'code' => ErrorCode::TIMESTAMP_INVALID,
                    'message' => 'ts sebelum 2020-01-01, jam device belum tersinkron.',
                ];

                continue;
            }

            if (isset($seenTs[$ts])) {
                $duplicates[] = ['index' => $item['index'], 'ts' => $ts, 'seq' => $seq];

                continue;
            }
            $seenTs[$ts] = true;

            $warnings = [];
            $flags = 0;

            if ($ts > $serverNow + $this->futureToleranceS) {
                $flags |= QualityFlag::CLOCK_FUTURE;
                $warnings[] = [
                    'code' => ErrorCode::W_CLOCK_FUTURE,
                    'message' => sprintf('ts %d detik di depan waktu server.', $ts - $serverNow),
                ];
            }

            $readings = [];
            $seenS = [];
            foreach ($item['readings'] ?? [] as $r) {
                $s = (string) $r['s'];
                if (isset($seenS[$s])) {
                    $warnings[] = [
                        'code' => ErrorCode::W_DUPLICATE_CHANNEL_IN_PACKET,
                        'message' => "Sensor '{$s}' muncul lebih dari sekali, yang pertama dipakai.",
                    ];

                    continue;
                }
                $seenS[$s] = true;
                $readings[] = ['s' => $s, 'v' => (float) $r['v']];
            }

            $packets[] = [
                'index' => $item['index'],
                'ts' => $ts,
                'seq' => $seq,
                'battery_v' => isset($item['battery_v']) ? (float) $item['battery_v'] : null,
                'rssi' => isset($item['rssi']) ? (int) $item['rssi'] : null,
                'flags' => $flags,
                'readings' => $readings,
                'warnings' => $warnings,
            ];
        }

        return ['packets' => $packets, 'duplicates' => $duplicates, 'rejected' => $rejected];
    }
}
