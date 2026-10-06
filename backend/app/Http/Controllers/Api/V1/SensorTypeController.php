<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\SensorType;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SensorTypeController
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) ($request->query('per_page', 20)), 100);

        return ApiResponse::paginated(SensorType::orderBy('id')->paginate($perPage), fn (SensorType $t) => [
            'id' => $t->id, 'code' => $t->code, 'name' => $t->name, 'unit' => $t->unit, 'kind' => $t->kind,
            'min_value' => $t->min_value, 'max_value' => $t->max_value, 'precision' => $t->precision,
            'counter_factor' => $t->counter_factor, 'derived_unit' => $t->derived_unit,
            'error_codes' => $t->error_codes, 'default_agg' => $t->default_agg,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => 'required|string|max:32|unique:sensor_types,code',
            'name' => 'required|string|max:100',
            'unit' => 'required|string|max:16',
            'kind' => 'required|string|in:gauge,counter,angle',
            'min_value' => 'required|numeric',
            'max_value' => 'required|numeric|gt:min_value',
            'precision' => 'sometimes|integer|min:0|max:6',
            'counter_factor' => 'nullable|numeric',
            'derived_unit' => 'nullable|string|max:16',
            'error_codes' => 'sometimes|array',
            'error_codes.*' => 'numeric',
            'default_agg' => 'sometimes|string|in:avg,min,max,sum',
        ]);

        $type = SensorType::create($data);

        return ApiResponse::created(['id' => $type->id, 'code' => $type->code]);
    }

    public function update(int $sensorType, Request $request): JsonResponse
    {
        $type = SensorType::findOrFail($sensorType);
        $data = $request->validate([
            'name' => 'sometimes|string|max:100',
            'unit' => 'sometimes|string|max:16',
            'min_value' => 'sometimes|numeric',
            'max_value' => 'sometimes|numeric',
            'precision' => 'sometimes|integer|min:0|max:6',
            'counter_factor' => 'nullable|numeric',
            'derived_unit' => 'nullable|string|max:16',
            'error_codes' => 'sometimes|array',
            'error_codes.*' => 'numeric',
            'default_agg' => 'sometimes|string|in:avg,min,max,sum',
        ]);

        $type->update($data);

        return ApiResponse::ok(['id' => $type->id, 'code' => $type->code]);
    }
}
