<?php

namespace App\Services;

use App\Domain\Aggregation\BucketAggregator;
use App\Support\Time;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class AggregationService
{
    /** @var array<int, array{device_id:int, sensor_type_id:int, kind:string, counter_factor:float}> */
    private array $channelMetaCache = [];

    /**
     * Ambil antrian (SKIP LOCKED), hitung 1h dari raw, turunkan 1d WIB, upsert, hapus antrian.
     */
    public function refreshOnce(int $limit = 500): int
    {
        return DB::transaction(function () use ($limit) {
            $rows = DB::select(
                'SELECT channel_id, bucket_start FROM aggregate_refresh_queue ORDER BY bucket_start LIMIT ? FOR UPDATE SKIP LOCKED',
                [$limit]
            );

            if ($rows === []) {
                return 0;
            }

            $touchedDays = [];
            foreach ($rows as $r) {
                $channelId = (int) $r->channel_id;
                $hourStart = Time::epoch($r->bucket_start);
                $this->refreshHour($channelId, $hourStart);
                $touchedDays[$channelId.':'.Time::wibDayStart($hourStart)] = [$channelId, Time::wibDayStart($hourStart)];
            }

            foreach ($touchedDays as [$channelId, $dayStart]) {
                $this->refreshDay($channelId, $dayStart);
            }

            $placeholders = implode(',', array_fill(0, count($rows), '(?,?)'));
            $bindings = [];
            foreach ($rows as $r) {
                $bindings[] = $r->channel_id;
                $bindings[] = $r->bucket_start;
            }
            DB::delete("DELETE FROM aggregate_refresh_queue WHERE (channel_id, bucket_start) IN ({$placeholders})", $bindings);

            return count($rows);
        });
    }

    public function refreshAll(int $batchSize = 500, int $maxBatches = 2000): int
    {
        $total = 0;
        $batches = 0;
        while (($n = $this->refreshOnce($batchSize)) > 0 && $batches < $maxBatches) {
            $total += $n;
            $batches++;
        }

        return $total;
    }

    public function rebuild(CarbonImmutable $from, CarbonImmutable $to): int
    {
        DB::statement(
            "INSERT INTO aggregate_refresh_queue (channel_id, bucket_start)
             SELECT DISTINCT channel_id, date_trunc('hour', time) FROM sensor_readings
             WHERE time >= ? AND time < ?
             ON CONFLICT DO NOTHING",
            [Time::db($from), Time::db($to)]
        );

        return $this->refreshAll();
    }

    private function channelMeta(int $channelId): array
    {
        if (! isset($this->channelMetaCache[$channelId])) {
            $row = DB::selectOne(
                'SELECT c.device_id, t.id AS sensor_type_id, t.kind, t.counter_factor
                 FROM device_channels c JOIN sensor_types t ON t.id = c.sensor_type_id
                 WHERE c.id = ?',
                [$channelId]
            );

            $this->channelMetaCache[$channelId] = [
                'device_id' => (int) $row->device_id,
                'sensor_type_id' => (int) $row->sensor_type_id,
                'kind' => $row->kind,
                'counter_factor' => $row->counter_factor !== null ? (float) $row->counter_factor : 0.2,
            ];
        }

        return $this->channelMetaCache[$channelId];
    }

    private function refreshHour(int $channelId, int $hourStart): void
    {
        $meta = $this->channelMeta($channelId);
        $hourEnd = $hourStart + 3600;

        $rows = DB::select(
            'SELECT extract(epoch FROM r.time)::bigint AS t, r.value, r.quality_flags AS flags, p.seq
             FROM sensor_readings r LEFT JOIN device_packets p ON p.device_id = r.device_id AND p.time = r.time
             WHERE r.channel_id = ? AND r.time >= ? AND r.time < ?
             ORDER BY r.time',
            [$channelId, Time::db($hourStart), Time::db($hourEnd)]
        );

        if ($rows === []) {
            DB::delete('DELETE FROM reading_aggregates WHERE channel_id = ? AND bucket_interval = ? AND bucket_start = ?', [$channelId, '1h', Time::db($hourStart)]);

            return;
        }

        $readings = array_map(fn ($r) => [
            'time' => (int) $r->t,
            'value' => $r->value !== null ? (float) $r->value : null,
            'flags' => (int) $r->flags,
            'seq' => $r->seq !== null ? (int) $r->seq : null,
        ], $rows);

        $previous = null;
        if ($meta['kind'] === 'counter') {
            $prevRow = DB::selectOne(
                'SELECT extract(epoch FROM r.time)::bigint AS t, r.value, p.seq
                 FROM sensor_readings r LEFT JOIN device_packets p ON p.device_id = r.device_id AND p.time = r.time
                 WHERE r.channel_id = ? AND r.time < ? AND r.quality_flags = 0 AND r.value IS NOT NULL
                 ORDER BY r.time DESC LIMIT 1',
                [$channelId, Time::db($hourStart)]
            );
            if ($prevRow) {
                $previous = ['time' => (int) $prevRow->t, 'counter' => (float) $prevRow->value, 'seq' => $prevRow->seq !== null ? (int) $prevRow->seq : 0];
            }
        }

        $agg = BucketAggregator::aggregate($meta['kind'], $readings, $previous, $meta['counter_factor']);
        $this->upsert($channelId, '1h', $hourStart, $meta, $agg);
    }

    private function refreshDay(int $channelId, int $dayStart): void
    {
        $meta = $this->channelMeta($channelId);
        $dayEnd = $dayStart + 86400;

        $rows = DB::select(
            'SELECT sample_count, good_count, avg_value, min_value, max_value, sum_value, sum_x, sum_y
             FROM reading_aggregates WHERE channel_id = ? AND bucket_interval = ? AND bucket_start >= ? AND bucket_start < ?',
            [$channelId, '1h', Time::db($dayStart), Time::db($dayEnd)]
        );

        if ($rows === []) {
            DB::delete('DELETE FROM reading_aggregates WHERE channel_id = ? AND bucket_interval = ? AND bucket_start = ?', [$channelId, '1d', Time::db($dayStart)]);

            return;
        }

        $hours = array_map(fn ($r) => [
            'sample_count' => (int) $r->sample_count,
            'good_count' => (int) $r->good_count,
            'avg' => $r->avg_value !== null ? (float) $r->avg_value : null,
            'min' => $r->min_value !== null ? (float) $r->min_value : null,
            'max' => $r->max_value !== null ? (float) $r->max_value : null,
            'sum' => $r->sum_value !== null ? (float) $r->sum_value : null,
            'sum_x' => $r->sum_x !== null ? (float) $r->sum_x : null,
            'sum_y' => $r->sum_y !== null ? (float) $r->sum_y : null,
        ], $rows);

        $agg = BucketAggregator::rollup($meta['kind'], $hours);
        $this->upsert($channelId, '1d', $dayStart, $meta, $agg);
    }

    private function upsert(int $channelId, string $interval, int $bucketStart, array $meta, array $agg): void
    {
        DB::statement(
            'INSERT INTO reading_aggregates
                (channel_id, bucket_interval, bucket_start, device_id, sensor_type_id, sample_count, good_count, avg_value, min_value, max_value, sum_value, sum_x, sum_y, computed_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?, now())
             ON CONFLICT (channel_id, bucket_interval, bucket_start) DO UPDATE SET
                sample_count = EXCLUDED.sample_count, good_count = EXCLUDED.good_count,
                avg_value = EXCLUDED.avg_value, min_value = EXCLUDED.min_value, max_value = EXCLUDED.max_value,
                sum_value = EXCLUDED.sum_value, sum_x = EXCLUDED.sum_x, sum_y = EXCLUDED.sum_y, computed_at = now()',
            [
                $channelId, $interval, Time::db($bucketStart), $meta['device_id'], $meta['sensor_type_id'],
                $agg['sample_count'], $agg['good_count'], $agg['avg'], $agg['min'], $agg['max'], $agg['sum'], $agg['sum_x'], $agg['sum_y'],
            ]
        );
    }
}
