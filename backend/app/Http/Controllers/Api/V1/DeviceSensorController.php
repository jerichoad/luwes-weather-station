<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Sensor\InstallSensorRequest;
use App\Models\Device;
use App\Models\DeviceChannel;
use App\Models\Sensor;
use App\Models\SensorInstallation;
use App\Services\SensorInstallationService;
use App\Support\ApiResponse;
use App\Support\Time;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceSensorController
{
    public function index(int $device, Request $request): JsonResponse
    {
        $d = Device::findOrFail($device);
        $q = SensorInstallation::with('sensor.sensorType', 'channel')
            ->whereIn('device_channel_id', DeviceChannel::where('device_id', $d->id)->select('id'));

        if (! $request->boolean('include_history')) {
            $q->whereNull('removed_at');
        }

        $items = $q->orderByDesc('installed_at')->get()->map(fn (SensorInstallation $i) => [
            'installation_id' => $i->id,
            'sensor' => ['id' => $i->sensor->id, 'serial_number' => $i->sensor->serial_number, 'sensor_type' => $i->sensor->sensorType->code],
            'channel' => ['id' => $i->channel->id, 'channel_key' => $i->channel->channel_key],
            'installed_at' => Time::iso($i->installed_at),
            'removed_at' => Time::iso($i->removed_at),
            'removal_reason' => $i->removal_reason,
        ])->values();

        return ApiResponse::ok($items);
    }

    public function store(int $device, InstallSensorRequest $request, SensorInstallationService $svc): JsonResponse
    {
        $d = Device::findOrFail($device);
        $sensor = Sensor::findOrFail($request->validated('sensor_id'));
        $at = $request->validated('installed_at') ? CarbonImmutable::parse($request->validated('installed_at'))->utc() : null;

        $inst = $svc->install($d, $sensor, $request->validated('channel_key'), $at);

        return ApiResponse::created([
            'installation_id' => $inst->id,
            'sensor' => ['id' => $inst->sensor->id, 'serial_number' => $inst->sensor->serial_number, 'sensor_type' => $inst->sensor->sensorType->code],
            'device' => ['id' => $d->id, 'code' => $d->code],
            'channel' => ['id' => $inst->channel->id, 'channel_key' => $inst->channel->channel_key],
            'installed_at' => Time::iso($inst->installed_at),
            'removed_at' => null,
        ]);
    }

    public function destroy(int $device, int $sensor, Request $request, SensorInstallationService $svc): JsonResponse
    {
        $d = Device::findOrFail($device);
        $s = Sensor::findOrFail($sensor);
        $at = $request->input('removed_at') ? CarbonImmutable::parse($request->input('removed_at'))->utc() : null;
        $reason = $request->input('reason', 'Dilepas');

        $inst = $svc->uninstall($d, $s, $at, $reason);

        return ApiResponse::ok([
            'installation_id' => $inst->id,
            'removed_at' => Time::iso($inst->removed_at),
            'removal_reason' => $inst->removal_reason,
        ]);
    }
}
