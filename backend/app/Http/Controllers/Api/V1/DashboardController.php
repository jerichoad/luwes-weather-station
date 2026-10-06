<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Device;
use App\Services\ReadingQueryService;
use App\Support\ApiResponse;
use App\Support\Time;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

class DashboardController
{
    public function overview(ReadingQueryService $svc): JsonResponse
    {
        $now = CarbonImmutable::now('UTC');
        $devices = Device::with('location')
            ->where('status', '!=', 'decommissioned')
            ->orderBy('id')->limit(200)->get();

        $byStatus = $devices->countBy(fn (Device $d) => $d->status->value);
        $byConn = $devices->countBy(fn (Device $d) => $d->connectivity($now));

        $latest = $svc->latestForDevices($devices);

        $stations = $devices->map(fn (Device $d) => [
            'id' => $d->id, 'code' => $d->code, 'name' => $d->name,
            'location' => $d->location?->name,
            'status' => $d->status->value,
            'connectivity' => $d->connectivity($now),
            'last_seen_at' => Time::iso($d->last_seen_at),
            'latest' => $latest[$d->id] ?? (object) [],
        ])->values();

        return ApiResponse::ok([
            'totals' => [
                'devices' => $devices->count(),
                'active' => $byStatus['active'] ?? 0,
                'maintenance' => $byStatus['maintenance'] ?? 0,
                'provisioned' => $byStatus['provisioned'] ?? 0,
                'online' => $byConn['online'] ?? 0,
                'stale' => $byConn['stale'] ?? 0,
                'offline' => $byConn['offline'] ?? 0,
                'never' => $byConn['never'] ?? 0,
            ],
            'stations' => $stations,
            'generated_at' => $now->toIso8601ZuluString(),
        ]);
    }
}
