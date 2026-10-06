<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Reading\ReadingsRequest;
use App\Http\Requests\Reading\SummaryRequest;
use App\Models\Device;
use App\Services\ReadingQueryService;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

class ReadingController
{
    public function index(ReadingsRequest $request, ReadingQueryService $svc): JsonResponse
    {
        $data = $request->validated();
        $to = isset($data['to']) ? CarbonImmutable::parse($data['to'])->utc() : CarbonImmutable::now('UTC');
        $from = isset($data['from']) ? CarbonImmutable::parse($data['from'])->utc() : $to->subHours(24);

        $types = array_map('trim', explode(',', $data['sensor_type']));
        $types = array_slice(array_filter($types), 0, 4);

        $result = $svc->timeSeries([
            'device_id' => (int) $data['device_id'],
            'sensor_types' => $types,
            'from' => $from,
            'to' => $to,
            'interval' => $data['interval'] ?? null,
            'agg' => $data['agg'] ?? null,
            'include_flagged' => (bool) ($data['include_flagged'] ?? false),
            'cursor' => isset($data['cursor']) ? (int) $data['cursor'] : null,
            'limit' => isset($data['limit']) ? (int) $data['limit'] : null,
        ]);

        return ApiResponse::ok($result['data'], $result['meta']);
    }

    public function latest(int $device, ReadingQueryService $svc): JsonResponse
    {
        $d = Device::findOrFail($device);

        return ApiResponse::ok($svc->latest($d));
    }

    public function summary(SummaryRequest $request, ReadingQueryService $svc): JsonResponse
    {
        $data = $request->validated();
        $device = Device::findOrFail($data['device_id']);

        $from = CarbonImmutable::createFromFormat('Y-m-d', $data['from'], 'Asia/Jakarta');
        $to = CarbonImmutable::createFromFormat('Y-m-d', $data['to'], 'Asia/Jakarta');

        $page = (int) ($data['page'] ?? 1);
        $perPage = (int) ($data['per_page'] ?? 31);

        $result = $svc->dailySummary($device, $from, $to, $page, $perPage);

        return ApiResponse::ok($result['items'], [
            'page' => $page, 'per_page' => $perPage,
            'total' => $result['total'],
            'last_page' => (int) ceil($result['total'] / $perPage),
            'timezone' => 'Asia/Jakarta',
        ]);
    }
}
