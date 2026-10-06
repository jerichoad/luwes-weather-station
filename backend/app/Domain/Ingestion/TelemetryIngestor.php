<?php

namespace App\Domain\Ingestion;

use App\Domain\Devices\DeviceStatus;
use App\Domain\Quality\QualityFlag;
use App\Models\Device;
use App\Support\ErrorCode;
use App\Support\Time;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class TelemetryIngestor
{
    public const SOURCE_LIVE = 1;

    public const SOURCE_BATCH = 2;

    private const READING_CHUNK = 1000;

    private const PACKET_CHUNK = 500;

    public function __construct(private readonly PacketNormalizer $normalizer) {}

    public static function make(): self
    {
        return new self(new PacketNormalizer(
            (int) config('weather.ingest.future_tolerance_s'),
            (int) config('weather.ingest.min_ts'),
        ));
    }

    /**
     * @param  list<array{index:int, ts:int, seq:int, battery_v?:?float, rssi?:?int, readings:list<array{s:string, v:float|int}>}>  $items
     * @param  list<array{index:int, ts:?int, seq:?int, code:string, message:string, details?:array}>  $preRejected  item yang gagal validasi schema
     * @return array{items: list<array<string, mixed>>, summary: array{received:int, accepted:int, duplicate:int, rejected:int}}
     */
    public function ingest(Device $device, ?string $firmware, array $items, int $source, array $preRejected = []): array
    {
        $now = CarbonImmutable::now('UTC');
        $normalized = $this->normalizer->normalize($items, $now->getTimestamp());
        $ctx = DeviceContext::load($device->id, $device->status->value);

        $enriched = [];
        foreach ($normalized['packets'] as $p) {
            $enriched[$p['ts']] = ['packet' => $p, 'result' => ReadingEnricher::enrich($ctx, $p)];
        }

        $results = [];

        DB::transaction(function () use ($device, $firmware, $source, $now, $enriched, &$results) {
            $acceptedTs = $this->insertPackets($device->id, $firmware, $source, $now, $enriched);

            $rows = [];
            foreach ($acceptedTs as $ts) {
                array_push($rows, ...$enriched[$ts]['result']['rows']);
            }
            self::insertReadings($rows);
            self::enqueueBuckets($rows);

            $collisions = $this->detectCollisions($device->id, $enriched, $acceptedTs);

            foreach ($enriched as $ts => $e) {
                $p = $e['packet'];
                if (isset($acceptedTs[$ts])) {
                    $results[$p['index']] = [
                        'index' => $p['index'], 'ts' => $ts, 'seq' => $p['seq'], 'status' => 'accepted',
                        'readings_stored' => $e['result']['stored'],
                        'readings_flagged' => $e['result']['flagged'],
                        'readings_skipped' => $e['result']['skipped'],
                        'warnings' => array_values(array_merge($p['warnings'], $e['result']['warnings'])),
                    ];
                } else {
                    $warnings = [];
                    if (isset($collisions[$ts]) && $collisions[$ts] !== $p['seq']) {
                        $warnings[] = ['code' => ErrorCode::W_TS_COLLISION, 'message' => "Paket dengan ts sama sudah ada dengan seq {$collisions[$ts]}."];
                    }
                    $results[$p['index']] = ['index' => $p['index'], 'ts' => $ts, 'seq' => $p['seq'], 'status' => 'duplicate', 'warnings' => $warnings];
                }
            }

            $this->updateDeviceHealth($device, $firmware, $source, $now, $enriched, $acceptedTs);
        });

        foreach ($normalized['duplicates'] as $d) {
            $results[$d['index']] = ['index' => $d['index'], 'ts' => $d['ts'], 'seq' => $d['seq'], 'status' => 'duplicate', 'warnings' => []];
        }
        foreach ($normalized['rejected'] as $r) {
            $results[$r['index']] = [
                'index' => $r['index'], 'ts' => $r['ts'], 'seq' => $r['seq'], 'status' => 'rejected',
                'error' => ['code' => $r['code'], 'message' => $r['message']],
            ];
        }
        foreach ($preRejected as $r) {
            $results[$r['index']] = [
                'index' => $r['index'], 'ts' => $r['ts'], 'seq' => $r['seq'], 'status' => 'rejected',
                'error' => ['code' => $r['code'], 'message' => $r['message'], 'details' => $r['details'] ?? []],
            ];
        }

        ksort($results);
        $items = array_values($results);
        $count = fn (string $s) => count(array_filter($items, fn ($i) => $i['status'] === $s));

        return [
            'items' => $items,
            'summary' => [
                'received' => count($items),
                'accepted' => $count('accepted'),
                'duplicate' => $count('duplicate'),
                'rejected' => $count('rejected'),
            ],
        ];
    }

    /**
     * @return array<int, int> ts => ts untuk paket yang benar-benar baru
     */
    private function insertPackets(int $deviceId, ?string $firmware, int $source, CarbonImmutable $now, array $enriched): array
    {
        $accepted = [];
        $receivedAt = Time::db($now);

        foreach (array_chunk($enriched, self::PACKET_CHUNK, true) as $chunk) {
            $placeholders = [];
            $bindings = [];
            foreach ($chunk as $ts => $e) {
                $p = $e['packet'];
                $placeholders[] = '(?,?,?,?,?,?,?,?,?,?)';
                array_push(
                    $bindings,
                    Time::db($ts), $deviceId, $p['seq'], $receivedAt, $source,
                    $p['battery_v'], $p['rssi'], $firmware, count($p['readings']), $p['flags'],
                );
            }

            $inserted = DB::select(
                'INSERT INTO device_packets (time, device_id, seq, received_at, source, battery_v, rssi, firmware_version, reading_count, quality_flags)
                 VALUES '.implode(',', $placeholders).'
                 ON CONFLICT (device_id, time) DO NOTHING
                 RETURNING extract(epoch FROM time)::bigint AS ts',
                $bindings
            );

            foreach ($inserted as $row) {
                $accepted[(int) $row->ts] = (int) $row->ts;
            }
        }

        return $accepted;
    }

    /**
     * @param  list<array{time:int, device_id:int, channel_id:int, sensor_id:int, raw_value:float, value:?float, quality_flags:int}>  $rows
     */
    public static function insertReadings(array $rows): void
    {
        foreach (array_chunk($rows, self::READING_CHUNK) as $chunk) {
            $placeholders = [];
            $bindings = [];
            foreach ($chunk as $r) {
                $placeholders[] = '(?,?,?,?,?,?,?)';
                array_push($bindings, Time::db($r['time']), $r['device_id'], $r['channel_id'], $r['sensor_id'], $r['raw_value'], $r['value'], $r['quality_flags']);
            }

            DB::insert(
                'INSERT INTO sensor_readings (time, device_id, channel_id, sensor_id, raw_value, value, quality_flags)
                 VALUES '.implode(',', $placeholders).'
                 ON CONFLICT (channel_id, time) DO NOTHING',
                $bindings
            );
        }
    }

    /**
     * Tandai bucket jam yang tersentuh. Channel counter juga menandai jam berikutnya,
     * karena delta bacaan pertama jam H+1 bergantung pada bacaan terakhir jam H.
     *
     * @param  list<array{time:int, channel_id:int, kind?:string}>  $rows
     */
    public static function enqueueBuckets(array $rows): void
    {
        $buckets = [];
        foreach ($rows as $r) {
            $hour = Time::hourStart($r['time']);
            $buckets[$r['channel_id'].':'.$hour] = [$r['channel_id'], $hour];
            if (($r['kind'] ?? null) === 'counter') {
                $buckets[$r['channel_id'].':'.($hour + 3600)] = [$r['channel_id'], $hour + 3600];
            }
        }

        foreach (array_chunk(array_values($buckets), 1000) as $chunk) {
            $placeholders = [];
            $bindings = [];
            foreach ($chunk as [$channelId, $hour]) {
                $placeholders[] = '(?,?)';
                array_push($bindings, $channelId, Time::db($hour));
            }

            DB::insert(
                'INSERT INTO aggregate_refresh_queue (channel_id, bucket_start) VALUES '.implode(',', $placeholders).' ON CONFLICT DO NOTHING',
                $bindings
            );
        }
    }

    /**
     * @return array<int, int> ts => seq yang sudah ada di DB untuk paket duplikat
     */
    private function detectCollisions(int $deviceId, array $enriched, array $acceptedTs): array
    {
        $dupTs = array_values(array_diff(array_keys($enriched), array_keys($acceptedTs)));
        if ($dupTs === []) {
            return [];
        }

        $in = implode(',', array_fill(0, count($dupTs), '?'));
        $rows = DB::select(
            "SELECT extract(epoch FROM time)::bigint AS ts, seq FROM device_packets WHERE device_id = ? AND time IN ({$in})",
            array_merge([$deviceId], array_map(fn ($t) => Time::db($t), $dupTs))
        );

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->ts] = (int) $r->seq;
        }

        return $out;
    }

    private function updateDeviceHealth(Device $device, ?string $firmware, int $source, CarbonImmutable $now, array $enriched, array $acceptedTs): void
    {
        $nowDb = Time::db($now);

        $latest = null;
        foreach ($acceptedTs as $ts) {
            if (QualityFlag::has($enriched[$ts]['packet']['flags'], QualityFlag::CLOCK_FUTURE)) {
                continue;
            }
            if ($latest === null || $ts > $latest['ts']) {
                $latest = $enriched[$ts]['packet'];
            }
        }

        if ($latest === null) {
            DB::update(
                'UPDATE devices SET last_seen_at = GREATEST(COALESCE(last_seen_at, ?), ?), last_telemetry_received_at = ?, updated_at = ? WHERE id = ?',
                [$nowDb, $nowDb, $nowDb, $nowDb, $device->id]
            );
        } else {
            $latestDb = Time::db($latest['ts']);
            $clockOffset = $source === self::SOURCE_LIVE ? $now->getTimestamp() - $latest['ts'] : null;

            DB::update(
                'UPDATE devices SET
                    last_seen_at = GREATEST(COALESCE(last_seen_at, ?), ?),
                    last_telemetry_received_at = ?,
                    last_battery_v = CASE WHEN last_reading_at IS NULL OR last_reading_at <= ? THEN COALESCE(?, last_battery_v) ELSE last_battery_v END,
                    last_rssi = CASE WHEN last_reading_at IS NULL OR last_reading_at <= ? THEN COALESCE(?, last_rssi) ELSE last_rssi END,
                    firmware_version = CASE WHEN last_reading_at IS NULL OR last_reading_at <= ? THEN COALESCE(?, firmware_version) ELSE firmware_version END,
                    clock_offset_s = COALESCE(?, clock_offset_s),
                    last_reading_at = GREATEST(COALESCE(last_reading_at, ?), ?),
                    updated_at = ?
                 WHERE id = ?',
                [
                    $nowDb, $nowDb, $nowDb,
                    $latestDb, $latest['battery_v'],
                    $latestDb, $latest['rssi'],
                    $latestDb, $firmware,
                    $clockOffset,
                    $latestDb, $latestDb,
                    $nowDb, $device->id,
                ]
            );
        }

        if ($acceptedTs !== [] && $device->status === DeviceStatus::Provisioned) {
            $changed = DB::update(
                "UPDATE devices SET status = 'active', commissioned_at = COALESCE(commissioned_at, ?) WHERE id = ? AND status = 'provisioned'",
                [$nowDb, $device->id]
            );
            if ($changed) {
                DB::table('device_status_history')->insert([
                    'device_id' => $device->id,
                    'from_status' => 'provisioned',
                    'to_status' => 'active',
                    'reason' => 'Telemetry valid pertama diterima',
                    'changed_by' => null,
                    'changed_at' => $nowDb,
                ]);
                $device->status = DeviceStatus::Active;
            }
        }
    }

    /**
     * @param  array{ts:int, fw:?string, battery_v:?float, rssi:?int, uptime_s:int}  $hb
     */
    public function heartbeat(Device $device, array $hb): string
    {
        $now = CarbonImmutable::now('UTC');
        $nowDb = Time::db($now);

        return DB::transaction(function () use ($device, $hb, $nowDb) {
            $inserted = DB::select(
                'INSERT INTO device_heartbeats (time, device_id, received_at, battery_v, rssi, firmware_version, uptime_s)
                 VALUES (?,?,?,?,?,?,?) ON CONFLICT (device_id, time) DO NOTHING RETURNING time',
                [Time::db($hb['ts']), $device->id, $nowDb, $hb['battery_v'], $hb['rssi'], $hb['fw'], $hb['uptime_s']]
            );

            DB::update(
                'UPDATE devices SET last_seen_at = GREATEST(COALESCE(last_seen_at, ?), ?), updated_at = ? WHERE id = ?',
                [$nowDb, $nowDb, $nowDb, $device->id]
            );

            if ($inserted === []) {
                return 'duplicate';
            }

            DB::update(
                'UPDATE devices SET
                    last_uptime_s = ?,
                    last_boot_at = ?,
                    last_battery_v = COALESCE(?, last_battery_v),
                    last_rssi = COALESCE(?, last_rssi),
                    firmware_version = COALESCE(?, firmware_version)
                 WHERE id = ? AND (last_boot_at IS NULL OR last_uptime_s IS NULL OR ? >= extract(epoch FROM last_boot_at) + last_uptime_s - 60)',
                [
                    $hb['uptime_s'], Time::db($hb['ts'] - $hb['uptime_s']),
                    $hb['battery_v'], $hb['rssi'], $hb['fw'],
                    $device->id, $hb['ts'],
                ]
            );

            return 'accepted';
        });
    }
}
