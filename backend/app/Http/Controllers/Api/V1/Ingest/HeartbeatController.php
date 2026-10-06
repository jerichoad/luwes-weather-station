<?php

namespace App\Http\Controllers\Api\V1\Ingest;

use App\Domain\Ingestion\TelemetryIngestor;
use App\Http\Requests\Ingest\StoreHeartbeatRequest;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

class HeartbeatController
{
    public function __invoke(StoreHeartbeatRequest $request): JsonResponse
    {
        $device = $request->attributes->get('device');
        $data = $request->validated();

        $status = TelemetryIngestor::make()->heartbeat($device, [
            'ts' => (int) $data['ts'],
            'fw' => $data['fw'],
            'battery_v' => isset($data['battery_v']) ? (float) $data['battery_v'] : null,
            'rssi' => isset($data['rssi']) ? (int) $data['rssi'] : null,
            'uptime_s' => (int) $data['uptime_s'],
        ]);

        return ApiResponse::ok([
            'status' => $status,
            'server_time' => CarbonImmutable::now('UTC')->toIso8601ZuluString(),
        ], null, $status === 'accepted' ? 201 : 200);
    }
}
