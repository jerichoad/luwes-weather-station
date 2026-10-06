<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Device;
use App\Models\DeviceChannel;
use App\Services\ReadingQueryService;
use App\Support\ApiResponse;
use App\Support\Time;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DeviceHealthController
{
    public function show(int $device, ReadingQueryService $readings): JsonResponse
    {
        $d = Device::findOrFail($device);
        $now = CarbonImmutable::now('UTC');

        $channels = DeviceChannel::where('device_id', $d->id)->get();
        $latest = $readings->latestByChannel($channels->pluck('id')->all(), 86400);

        $noData = [];
        foreach ($channels as $ch) {
            if (! isset($latest[$ch->id]) || ($now->getTimestamp() - $latest[$ch->id]['time']) > 900) {
                $noData[] = $ch->channel_key;
            }
        }

        $trend = DB::select(
            "SELECT extract(epoch FROM date_trunc('hour', time))::bigint AS t, AVG(battery_v) AS v
             FROM device_packets WHERE device_id = ? AND time >= ? GROUP BY 1 ORDER BY 1",
            [$d->id, Time::db($now->subHours(24))]
        );

        $silentFor = $d->last_seen_at ? max(0, $now->getTimestamp() - $d->last_seen_at->getTimestamp()) : null;

        return ApiResponse::ok([
            'connectivity' => $d->connectivity($now),
            'last_seen_at' => Time::iso($d->last_seen_at),
            'silent_for_s' => $silentFor,
            'last_reading_at' => Time::iso($d->last_reading_at),
            'battery_v' => $d->last_battery_v,
            'rssi' => $d->last_rssi,
            'firmware_version' => $d->firmware_version,
            'uptime_s' => $d->last_uptime_s,
            'last_boot_at' => Time::iso($d->last_boot_at),
            'clock_offset_s' => $d->clock_offset_s,
            'channels_without_data' => $noData,
            'battery_trend_24h' => [
                'interval' => '1h',
                'timestamps' => array_map(fn ($r) => (int) $r->t * 1000, $trend),
                'values' => array_map(fn ($r) => $r->v !== null ? round((float) $r->v, 2) : null, $trend),
            ],
        ]);
    }
}
