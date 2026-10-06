<?php

namespace App\Services;

use App\Domain\Devices\DeviceKeyService;
use App\Domain\Devices\DeviceStateMachine;
use App\Domain\Devices\DeviceStatus;
use App\Models\Device;
use App\Models\DeviceChannel;
use App\Models\DeviceCredential;
use App\Models\DeviceStatusHistory;
use App\Models\SensorType;
use App\Support\ApiException;
use App\Support\ErrorCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class DeviceService
{
    public function keys(): DeviceKeyService
    {
        return new DeviceKeyService((string) config('weather.device_key_pepper'));
    }

    /**
     * @return array{device: Device, api_key: string}
     */
    public function register(array $data, ?string $plainKey = null): array
    {
        return DB::transaction(function () use ($data, $plainKey) {
            $device = Device::create([
                'code' => $data['code'],
                'name' => $data['name'],
                'location_id' => $data['location_id'],
                'status' => DeviceStatus::Provisioned,
            ]);

            DeviceStatusHistory::create([
                'device_id' => $device->id,
                'from_status' => null,
                'to_status' => DeviceStatus::Provisioned->value,
                'reason' => 'Registrasi device',
                'changed_at' => now(),
            ]);

            if ($data['create_default_channels'] ?? true) {
                foreach (SensorType::orderBy('id')->get() as $type) {
                    DeviceChannel::create([
                        'device_id' => $device->id,
                        'sensor_type_id' => $type->id,
                        'channel_key' => $type->code,
                        'label' => $type->name,
                    ]);
                }
            }

            $key = $this->issueKey($device, $plainKey);

            return ['device' => $device, 'api_key' => $key];
        });
    }

    public function issueKey(Device $device, ?string $plainKey = null): string
    {
        $keys = $this->keys();
        $plain = $plainKey ?? $keys->generate();

        DeviceCredential::create([
            'device_id' => $device->id,
            'key_prefix' => $keys->prefix($plain),
            'key_hash' => $keys->hash($plain),
            'created_at' => now(),
        ]);

        return $plain;
    }

    /**
     * @return array{api_key:string, key_prefix:string, previous_keys_expire_at:string}
     */
    public function rotateKey(Device $device): array
    {
        if ($device->status === DeviceStatus::Decommissioned) {
            throw new ApiException(403, ErrorCode::DEVICE_DECOMMISSIONED, 'Device sudah di-decommission.');
        }

        return DB::transaction(function () use ($device) {
            $expiresAt = CarbonImmutable::now()->addHours((int) config('weather.credential_grace_hours', 24));

            DeviceCredential::where('device_id', $device->id)
                ->whereNull('revoked_at')
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $expiresAt))
                ->update(['expires_at' => $expiresAt]);

            $plain = $this->issueKey($device);

            return [
                'api_key' => $plain,
                'key_prefix' => $this->keys()->prefix($plain),
                'previous_keys_expire_at' => $expiresAt->toIso8601ZuluString(),
            ];
        });
    }

    public function transition(Device $device, DeviceStatus $to, ?string $reason): Device
    {
        $from = $device->status;

        if ($from === $to) {
            return $device;
        }

        if (! DeviceStateMachine::canTransition($from, $to)) {
            throw ApiException::conflict(
                ErrorCode::INVALID_STATUS_TRANSITION,
                "Transisi status {$from->value} → {$to->value} tidak diizinkan.",
                [[
                    'field' => 'status',
                    'code' => ErrorCode::INVALID_STATUS_TRANSITION,
                    'message' => 'Transisi yang diizinkan: '.(implode(', ', $from->allowedTransitionValues()) ?: '-'),
                    'allowed_transitions' => $from->allowedTransitionValues(),
                ]]
            );
        }

        return DB::transaction(function () use ($device, $from, $to, $reason) {
            $now = CarbonImmutable::now();
            $device->status = $to;

            if ($to === DeviceStatus::Active && $device->commissioned_at === null) {
                $device->commissioned_at = $now;
            }

            if ($to === DeviceStatus::Decommissioned) {
                $device->decommissioned_at = $now;

                DeviceCredential::where('device_id', $device->id)->whereNull('revoked_at')->update(['revoked_at' => $now]);

                DB::update(
                    "UPDATE sensor_installations SET removed_at = ?, removal_reason = 'device_decommissioned', updated_at = ?
                     WHERE removed_at IS NULL AND device_channel_id IN (SELECT id FROM device_channels WHERE device_id = ?)",
                    [$now, $now, $device->id]
                );
            }

            $device->save();

            DeviceStatusHistory::create([
                'device_id' => $device->id,
                'from_status' => $from->value,
                'to_status' => $to->value,
                'reason' => $reason,
                'changed_at' => $now,
            ]);

            return $device;
        });
    }
}
