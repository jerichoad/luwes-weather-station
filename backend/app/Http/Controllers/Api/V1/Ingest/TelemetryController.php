<?php

namespace App\Http\Controllers\Api\V1\Ingest;

use App\Domain\Ingestion\TelemetryIngestor;
use App\Http\Requests\Ingest\StoreBatchRequest;
use App\Http\Requests\Ingest\StoreTelemetryRequest;
use App\Support\ApiResponse;
use App\Support\ErrorCode;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class TelemetryController
{
    public function single(StoreTelemetryRequest $request): JsonResponse
    {
        $device = $request->attributes->get('device');
        $data = $request->validated();

        $result = TelemetryIngestor::make()->ingest(
            $device, $data['fw'], [array_merge($data, ['index' => 0])], TelemetryIngestor::SOURCE_LIVE
        );

        $item = $result['items'][0];

        if ($item['status'] === 'rejected') {
            return ApiResponse::error(422, $item['error']['code'] ?? ErrorCode::VALIDATION_FAILED, $item['error']['message'] ?? 'Item ditolak.', $item['error']['details'] ?? []);
        }

        return ApiResponse::ok([
            'status' => $item['status'],
            'packet' => ['ts' => $item['ts'], 'seq' => $item['seq']],
            'readings' => ['stored' => $item['readings_stored'] ?? 0, 'flagged' => $item['readings_flagged'] ?? 0, 'skipped' => $item['readings_skipped'] ?? 0],
            'warnings' => $item['warnings'] ?? [],
            'server_time' => CarbonImmutable::now('UTC')->toIso8601ZuluString(),
        ], null, $item['status'] === 'accepted' ? 201 : 200);
    }

    public function batch(StoreBatchRequest $request): JsonResponse
    {
        $device = $request->attributes->get('device');
        $data = $request->validated();

        $validItems = [];
        $rejected = [];
        $itemRules = [
            'ts' => 'required|integer|min:1577836800',
            'seq' => 'required|integer|min:0',
            'battery_v' => 'nullable|numeric|between:0,10',
            'rssi' => 'nullable|integer|between:-150,0',
            'readings' => 'present|array|max:32',
            'readings.*.s' => 'required|string|max:32',
            'readings.*.v' => 'required|numeric',
        ];

        foreach ($data['batch'] as $i => $item) {
            $v = Validator::make($item, $itemRules);
            if ($v->fails()) {
                $rejected[] = [
                    'index' => $i, 'ts' => $item['ts'] ?? null, 'seq' => $item['seq'] ?? null,
                    'code' => ErrorCode::VALIDATION_FAILED, 'message' => 'Item validasi gagal.',
                    'details' => ErrorCode::detailsFromValidator($v, "batch.{$i}."),
                ];
            } else {
                $validItems[] = array_merge($v->validated(), ['index' => $i]);
            }
        }

        $result = TelemetryIngestor::make()->ingest(
            $device, $data['fw'], $validItems, TelemetryIngestor::SOURCE_BATCH, $rejected
        );

        $s = $result['summary'];
        $status = match (true) {
            $s['rejected'] === $s['received'] => 422,
            $s['duplicate'] === $s['received'] => 200,
            $s['accepted'] === $s['received'] => 201,
            default => 207,
        };

        return ApiResponse::ok([
            'summary' => $s, 'items' => $result['items'],
            'server_time' => CarbonImmutable::now('UTC')->toIso8601ZuluString(),
        ], null, $status);
    }
}
