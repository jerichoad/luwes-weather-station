<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Sensor\StoreSensorRequest;
use App\Models\Sensor;
use App\Models\SensorType;
use App\Support\ApiException;
use App\Support\ApiResponse;
use App\Support\ErrorCode;
use App\Support\Time;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SensorController
{
    public function index(Request $request): JsonResponse
    {
        $q = Sensor::with('sensorType');

        if ($type = $request->query('sensor_type')) {
            $q->whereHas('sensorType', fn ($w) => $w->where('code', $type));
        }
        if ($request->query('installed') === 'true') {
            $q->whereHas('activeInstallation');
        } elseif ($request->query('installed') === 'false') {
            $q->whereDoesntHave('activeInstallation');
        }
        if ($request->query('retired') === 'true') {
            $q->whereNotNull('retired_at');
        } elseif ($request->query('retired') === 'false') {
            $q->whereNull('retired_at');
        }

        $perPage = min((int) ($request->query('per_page', 20)), 100);

        return ApiResponse::paginated($q->orderBy('id')->paginate($perPage), fn (Sensor $s) => [
            'id' => $s->id, 'serial_number' => $s->serial_number,
            'sensor_type' => $s->sensorType->code, 'manufacturer' => $s->manufacturer, 'model' => $s->model,
            'retired_at' => Time::iso($s->retired_at),
        ]);
    }

    public function store(StoreSensorRequest $request): JsonResponse
    {
        $data = $request->validated();
        $type = SensorType::where('code', $data['sensor_type'])->firstOrFail();

        $sensor = Sensor::create(array_merge($data, ['sensor_type_id' => $type->id]));

        return ApiResponse::created(['id' => $sensor->id, 'serial_number' => $sensor->serial_number, 'sensor_type' => $type->code]);
    }

    public function show(int $sensor): JsonResponse
    {
        $s = Sensor::with('sensorType', 'installations.channel', 'calibrations')->findOrFail($sensor);

        return ApiResponse::ok([
            'id' => $s->id, 'serial_number' => $s->serial_number,
            'sensor_type' => $s->sensorType->code, 'manufacturer' => $s->manufacturer, 'model' => $s->model,
            'retired_at' => Time::iso($s->retired_at), 'notes' => $s->notes,
            'installations' => $s->installations->map(fn ($i) => [
                'id' => $i->id, 'channel_key' => $i->channel?->channel_key, 'device_id' => $i->channel?->device_id,
                'installed_at' => Time::iso($i->installed_at), 'removed_at' => Time::iso($i->removed_at),
            ])->values(),
            'calibrations' => $s->calibrations->map(fn ($c) => [
                'id' => $c->id, 'scale_factor' => $c->scale_factor, 'offset_value' => $c->offset_value,
                'effective_from' => Time::iso($c->effective_from), 'note' => $c->note,
            ])->values(),
        ]);
    }

    public function update(int $sensor, Request $request): JsonResponse
    {
        $s = Sensor::findOrFail($sensor);
        $data = $request->validate([
            'manufacturer' => 'sometimes|string|max:100',
            'model' => 'sometimes|string|max:100',
            'notes' => 'nullable|string',
            'retired' => 'sometimes|boolean',
        ]);

        if (isset($data['retired'])) {
            if ($data['retired'] && $s->activeInstallation()->exists()) {
                throw ApiException::conflict(ErrorCode::RESOURCE_IN_USE, 'Sensor masih terpasang, lepas dulu sebelum retire.');
            }
            $s->retired_at = $data['retired'] ? now() : null;
            unset($data['retired']);
        }

        $s->fill(array_intersect_key($data, array_flip(['manufacturer', 'model', 'notes'])));
        $s->save();

        return ApiResponse::ok(['id' => $s->id, 'serial_number' => $s->serial_number]);
    }

    public function destroy(int $sensor): JsonResponse
    {
        $s = Sensor::findOrFail($sensor);
        if ($s->activeInstallation()->exists()) {
            throw ApiException::conflict(ErrorCode::RESOURCE_IN_USE, 'Sensor masih terpasang.');
        }
        $s->delete();

        return ApiResponse::ok(null);
    }
}
