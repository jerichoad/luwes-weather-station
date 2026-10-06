<?php

namespace Database\Seeders;

use App\Domain\Ingestion\DeviceContext;
use App\Domain\Ingestion\ReadingEnricher;
use App\Domain\Ingestion\TelemetryIngestor;
use App\Models\Device;
use App\Support\Time;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class HistoricalDataSeeder extends Seeder
{
    public function run(): void
    {
        mt_srand(42);

        $now = CarbonImmutable::now('UTC')->startOfMinute();
        $start = $now->subDays(7);
        $totalMinutes = 7 * 24 * 60; // 10080

        $devices = Device::whereIn('code', ['WS-GRT-001', 'WS-DPK-001', 'WS-BGR-001'])->orderBy('id')->get();

        foreach ($devices as $device) {
            $this->command?->info("Seeding 7 days of telemetry for {$device->code}...");
            $this->seedDevice($device, $start, $totalMinutes, $now);
        }
    }

    private function seedDevice(Device $device, CarbonImmutable $start, int $totalMinutes, CarbonImmutable $now): void
    {
        $code = $device->code;
        $ctx = DeviceContext::load($device->id, 'active');

        $baseTemp = match ($code) {
            'WS-GRT-001' => 19.5,
            'WS-DPK-001' => 27.5,
            default => 24.5,
        };
        $basePressure = match ($code) {
            'WS-GRT-001' => 930.0,
            'WS-DPK-001' => 1005.0,
            default => 980.0,
        };

        $seq = 0;
        $rainCounter = 0;
        $windDir = mt_rand(0, 359);
        $windSpeed = 2.0;
        $battery = 3.95;

        $restartMinute = $code === 'WS-GRT-001' ? (3 * 24 * 60 + 10 * 60) : -1; // hari -4
        $dpkGapStart = 5 * 24 * 60 + 2 * 60; // hari -2, 3 jam
        $dpkGapEnd = $dpkGapStart + 180;
        $dpkDelayedStart = 2 * 24 * 60; // hari -5, 2 jam
        $dpkDelayedEnd = $dpkDelayedStart + 120;

        $packetsBatch = [];
        $readingsBatch = [];
        $heartbeatsBatch = [];
        $lastPacketTs = null;

        for ($m = 0; $m < $totalMinutes; $m++) {
            $t = $start->addMinutes($m)->getTimestamp();
            $hourWib = fmod((($t + 25200) % 86400) / 3600.0, 24.0);

            if ($code === 'WS-DPK-001' && $m >= $dpkGapStart && $m < $dpkGapEnd) {
                continue;
            }

            if ($m === $restartMinute) {
                $seq = 0;
                $rainCounter = 0;
            } else {
                $seq++;
            }

            $source = 1;
            $receivedAt = $t + mt_rand(1, 2);
            if ($code === 'WS-DPK-001' && $m >= $dpkDelayedStart && $m < $dpkDelayedEnd) {
                $source = 2;
                $receivedAt = $start->addMinutes($dpkDelayedEnd + 30)->getTimestamp();
            }

            $temp = $baseTemp + 5.0 * sin(2 * M_PI * ($hourWib - 8) / 24.0) + (mt_rand(-30, 30) / 100.0);
            $humidity = max(35.0, min(99.0, 78.0 - 2.8 * ($temp - $baseTemp) + (mt_rand(-20, 20) / 10.0)));
            $pressure = $basePressure + 1.2 * sin(2 * M_PI * $hourWib / 12.0) + (mt_rand(-10, 10) / 10.0);

            $solar = 0.0;
            if ($hourWib >= 6.0 && $hourWib <= 18.0) {
                $solar = max(0.0, 850.0 * sin(M_PI * ($hourWib - 6.0) / 12.0) + (mt_rand(-30, 30) / 10.0));
            }

            $windSpeed = max(0.2, min(14.0, $windSpeed + (mt_rand(-40, 40) / 100.0)));
            $windDir = ($windDir + mt_rand(-12, 12) + 360) % 360;

            if ($hourWib >= 14.0 && $hourWib <= 17.0 && mt_rand(1, 100) <= 35) {
                $rainCounter += mt_rand(1, 4);
            }

            if ($hourWib >= 7.0 && $hourWib <= 17.0) {
                $battery = min(4.18, $battery + 0.0003);
            } else {
                $battery = max(3.65, $battery - 0.0002);
            }
            $rssi = mt_rand(-85, -68);

            $readingsRaw = [
                ['s' => 'temp_air', 'v' => round($temp, 2)],
                ['s' => 'humidity', 'v' => round($humidity, 2)],
                ['s' => 'pressure', 'v' => round($pressure, 2)],
                ['s' => 'wind_speed', 'v' => round($windSpeed, 2)],
                ['s' => 'wind_dir', 'v' => (float) $windDir],
                ['s' => 'rain_counter', 'v' => (float) $rainCounter],
                ['s' => 'solar_rad', 'v' => round($solar, 2)],
            ];

            if ($code === 'WS-BGR-001') {
                if ($m % 400 === 50) {
                    $readingsRaw[1]['v'] = 150.0; // humidity OUT_OF_RANGE
                }
                if ($m % 500 === 120) {
                    $readingsRaw[0]['v'] = -999.0; // temp_air SENSOR_ERROR
                }
            }

            $packet = [
                'ts' => $t,
                'seq' => $seq,
                'battery_v' => round($battery, 2),
                'rssi' => $rssi,
                'flags' => 0,
                'readings' => $readingsRaw,
            ];

            $enriched = ReadingEnricher::enrich($ctx, $packet);

            $packetsBatch[] = [
                Time::db($t), $device->id, $seq, Time::db($receivedAt), $source,
                round($battery, 2), $rssi, '1.4.2', count($readingsRaw), 0,
            ];
            foreach ($enriched['rows'] as $r) {
                $readingsBatch[] = $r;
            }

            if ($m % 5 === 0) {
                $uptime = ($m - max(0, $restartMinute)) * 60;
                $heartbeatsBatch[] = [
                    Time::db($t), $device->id, Time::db($receivedAt),
                    round($battery, 2), $rssi, '1.4.2', $uptime,
                ];
            }

            $lastPacketTs = $t;

            if (count($packetsBatch) >= 1000) {
                $this->flushBatches($packetsBatch, $readingsBatch, $heartbeatsBatch);
            }
        }

        $this->flushBatches($packetsBatch, $readingsBatch, $heartbeatsBatch);

        if ($lastPacketTs) {
            $device->update([
                'last_seen_at' => $now,
                'last_telemetry_received_at' => $now,
                'last_reading_at' => Time::parse($lastPacketTs),
                'last_battery_v' => round($battery, 2),
                'last_rssi' => $rssi,
                'last_uptime_s' => ($totalMinutes - max(0, $restartMinute)) * 60,
                'last_boot_at' => Time::parse($start->addMinutes(max(0, $restartMinute))->getTimestamp()),
                'clock_offset_s' => 2,
                'firmware_version' => '1.4.2',
            ]);
        }
    }

    private function flushBatches(array &$packets, array &$readings, array &$heartbeats): void
    {
        if ($packets) {
            $chunks = array_chunk($packets, 500);
            foreach ($chunks as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '(?,?,?,?,?,?,?,?,?,?)'));
                $bindings = array_merge(...$chunk);
                DB::insert(
                    "INSERT INTO device_packets (time, device_id, seq, received_at, source, battery_v, rssi, firmware_version, reading_count, quality_flags)
                     VALUES {$placeholders} ON CONFLICT (device_id, time) DO NOTHING",
                    $bindings
                );
            }
            $packets = [];
        }

        if ($readings) {
            TelemetryIngestor::insertReadings($readings);
            TelemetryIngestor::enqueueBuckets($readings);
            $readings = [];
        }

        if ($heartbeats) {
            $chunks = array_chunk($heartbeats, 500);
            foreach ($chunks as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '(?,?,?,?,?,?,?)'));
                $bindings = array_merge(...$chunk);
                DB::insert(
                    "INSERT INTO device_heartbeats (time, device_id, received_at, battery_v, rssi, firmware_version, uptime_s)
                     VALUES {$placeholders} ON CONFLICT (device_id, time) DO NOTHING",
                    $bindings
                );
            }
            $heartbeats = [];
        }
    }
}
