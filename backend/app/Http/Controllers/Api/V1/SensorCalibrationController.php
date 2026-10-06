<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Calibration\CalibrationApplier;
use App\Models\Sensor;
use App\Models\SensorCalibration;
use App\Support\ApiException;
use App\Support\ApiResponse;
use App\Support\ErrorCode;
use App\Support\Time;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SensorCalibrationController
{
    public function index(int $sensor, Request $request): JsonResponse
    {
        $s = Sensor::findOrFail($sensor);
        $perPage = min((int) ($request->query('per_page', 20)), 100);

        return ApiResponse::paginated(
            SensorCalibration::where('sensor_id', $s->id)->orderByDesc('effective_from')->paginate($perPage),
            fn (SensorCalibration $c) => [
                'id' => $c->id, 'scale_factor' => $c->scale_factor, 'offset_value' => $c->offset_value,
                'effective_from' => Time::iso($c->effective_from), 'note' => $c->note,
                'created_at' => Time::iso($c->created_at),
            ]
        );
    }

    public function store(int $sensor, Request $request): JsonResponse
    {
        $s = Sensor::findOrFail($sensor);
        $data = $request->validate([
            'scale_factor' => 'required|numeric',
            'offset_value' => 'required|numeric',
            'effective_from' => 'nullable|date',
            'note' => 'nullable|string|max:255',
        ]);

        if (($data['scale_factor'] ?? 1) == 0) {
            throw ApiException::unprocessable(ErrorCode::VALIDATION_FAILED, 'scale_factor tidak boleh nol.', [[
                'field' => 'scale_factor', 'code' => 'OUT_OF_BOUNDS', 'message' => 'scale_factor tidak boleh nol.',
            ]]);
        }

        $effectiveFrom = isset($data['effective_from']) ? CarbonImmutable::parse($data['effective_from'])->utc() : CarbonImmutable::now('UTC');

        if ($effectiveFrom->isBefore(now()->subMinutes(5))) {
            throw ApiException::unprocessable(ErrorCode::VALIDATION_FAILED, 'Kalibrasi mundur belum didukung.', [[
                'field' => 'effective_from', 'code' => 'BACKDATED_NOT_SUPPORTED',
                'message' => 'effective_from harus >= sekarang - 5 menit.',
            ]]);
        }

        $cal = SensorCalibration::create([
            'sensor_id' => $s->id,
            'scale_factor' => $data['scale_factor'],
            'offset_value' => $data['offset_value'],
            'effective_from' => $effectiveFrom,
            'note' => $data['note'] ?? null,
        ]);

        $previewRaw = DB::scalar(
            'SELECT raw_value FROM sensor_readings WHERE sensor_id = ? AND quality_flags = 0 AND value IS NOT NULL ORDER BY time DESC LIMIT 1',
            [$s->id]
        );

        $preview = $previewRaw !== null ? [
            'raw' => (float) $previewRaw,
            'value' => round(CalibrationApplier::apply((float) $previewRaw, ['scale_factor' => $cal->scale_factor, 'offset_value' => $cal->offset_value]), 4),
        ] : null;

        return ApiResponse::created([
            'id' => $cal->id, 'scale_factor' => $cal->scale_factor, 'offset_value' => $cal->offset_value,
            'effective_from' => Time::iso($cal->effective_from), 'note' => $cal->note,
            'preview' => $preview,
        ]);
    }
}
