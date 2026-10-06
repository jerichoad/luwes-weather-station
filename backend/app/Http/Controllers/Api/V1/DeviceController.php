<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Devices\DeviceStatus;
use App\Http\Requests\Device\StoreDeviceRequest;
use App\Http\Requests\Device\UpdateDeviceRequest;
use App\Models\Device;
use App\Models\Location;
use App\Services\DeviceService;
use App\Support\ApiException;
use App\Support\ApiResponse;
use App\Support\ErrorCode;
use App\Support\Time;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceController
{
    public function index(Request $request): JsonResponse
    {
        $query = Device::with('location');

        if ($s = $request->query('status')) {
            $query->where('status', $s);
        }
        if ($loc = $request->query('location_id')) {
            $query->where('location_id', $loc);
        }
        if ($q = $request->query('q')) {
            $query->where(fn ($w) => $w->where('code', 'ILIKE', "%{$q}%")->orWhere('name', 'ILIKE', "%{$q}%"));
        }
        if ($sm = $request->query('silent_minutes')) {
            $threshold = now()->subMinutes((int) $sm);
            $query->where(fn ($w) => $w->where('last_seen_at', '<', $threshold)->orWhereNull('last_seen_at'));
        }
        if ($conn = $request->query('connectivity')) {
            $onS = (int) config('weather.connectivity.online_s');
            $offS = (int) config('weather.connectivity.offline_s');
            $query->where(fn ($w) => match ($conn) {
                'online' => $w->where('last_seen_at', '>=', now()->subSeconds($onS)),
                'stale' => $w->where('last_seen_at', '<', now()->subSeconds($onS))->where('last_seen_at', '>=', now()->subSeconds($offS)),
                'offline' => $w->where('last_seen_at', '<', now()->subSeconds($offS)),
                'never' => $w->whereNull('last_seen_at'),
                default => $w,
            });
        }

        $perPage = min((int) ($request->query('per_page', 20)), 100);
        $paginator = $query->orderBy('id')->paginate($perPage);

        return ApiResponse::paginated($paginator, fn (Device $d) => [
            'id' => $d->id, 'code' => $d->code, 'name' => $d->name,
            'status' => $d->status->value, 'connectivity' => $d->connectivity(),
            'location' => $d->location ? ['id' => $d->location->id, 'name' => $d->location->name] : null,
            'last_seen_at' => Time::iso($d->last_seen_at),
            'firmware_version' => $d->firmware_version,
        ]);
    }

    public function store(StoreDeviceRequest $request, DeviceService $svc): JsonResponse
    {
        $result = $svc->register($request->validated());
        $device = $result['device']->load('location');

        return ApiResponse::created([
            'id' => $device->id, 'code' => $device->code, 'name' => $device->name,
            'status' => $device->status->value,
            'location' => $device->location ? self::locationArray($device->location) : null,
            'credential' => [
                'api_key' => $result['api_key'],
                'key_prefix' => substr($result['api_key'], 0, 12),
                'notice' => 'Simpan sekarang, key tidak akan ditampilkan lagi.',
            ],
            'created_at' => Time::iso($device->created_at),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $device = Device::with('location', 'channels.sensorType', 'channels.activeInstallation.sensor')->findOrFail($id);

        return ApiResponse::ok([
            'id' => $device->id, 'code' => $device->code, 'name' => $device->name,
            'status' => $device->status->value,
            'allowed_transitions' => $device->status->allowedTransitionValues(),
            'connectivity' => $device->connectivity(),
            'location' => $device->location ? self::locationArray($device->location) : null,
            'health' => [
                'last_seen_at' => Time::iso($device->last_seen_at),
                'last_reading_at' => Time::iso($device->last_reading_at),
                'battery_v' => $device->last_battery_v,
                'rssi' => $device->last_rssi,
                'firmware_version' => $device->firmware_version,
            ],
            'channels' => $device->channels->map(fn ($ch) => [
                'id' => $ch->id, 'channel_key' => $ch->channel_key, 'label' => $ch->label,
                'sensor_type' => ['code' => $ch->sensorType->code, 'unit' => $ch->sensorType->unit],
                'current_sensor' => $ch->activeInstallation?->sensor ? [
                    'id' => $ch->activeInstallation->sensor->id,
                    'serial_number' => $ch->activeInstallation->sensor->serial_number,
                    'installed_at' => Time::iso($ch->activeInstallation->installed_at),
                ] : null,
            ])->values(),
            'created_at' => Time::iso($device->created_at),
            'updated_at' => Time::iso($device->updated_at),
        ]);
    }

    public function update(int $id, UpdateDeviceRequest $request, DeviceService $svc): JsonResponse
    {
        $device = Device::findOrFail($id);
        $data = $request->validated();

        if (isset($data['status'])) {
            $device = $svc->transition($device, DeviceStatus::from($data['status']), $data['status_reason'] ?? null);
            unset($data['status'], $data['status_reason']);
        }

        $update = array_intersect_key($data, array_flip(['name', 'location_id']));
        if ($update) {
            $device->update($update);
        }

        return $this->show($device->id);
    }

    public function destroy(int $id): JsonResponse
    {
        $device = Device::findOrFail($id);
        if ($device->status !== DeviceStatus::Decommissioned) {
            throw ApiException::conflict(ErrorCode::RESOURCE_IN_USE, 'Hanya device berstatus decommissioned yang bisa dihapus.');
        }
        $device->delete();

        return ApiResponse::ok(null);
    }

    private static function locationArray(Location $l): array
    {
        return ['id' => $l->id, 'code' => $l->code, 'name' => $l->name, 'latitude' => $l->latitude, 'longitude' => $l->longitude, 'altitude_m' => $l->altitude_m];
    }
}
