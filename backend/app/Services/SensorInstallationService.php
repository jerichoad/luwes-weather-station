<?php

namespace App\Services;

use App\Domain\Devices\DeviceStatus;
use App\Models\Device;
use App\Models\DeviceChannel;
use App\Models\Sensor;
use App\Models\SensorInstallation;
use App\Support\ApiException;
use App\Support\ErrorCode;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class SensorInstallationService
{
    public function install(Device $device, Sensor $sensor, ?string $channelKey, ?CarbonImmutable $installedAt): SensorInstallation
    {
        if ($device->status === DeviceStatus::Decommissioned) {
            throw new ApiException(403, ErrorCode::DEVICE_DECOMMISSIONED, 'Tidak bisa memasang sensor ke device yang sudah di-decommission.');
        }

        if ($sensor->retired_at !== null) {
            throw ApiException::conflict(ErrorCode::RESOURCE_IN_USE, 'Sensor sudah diafkirkan (retired).');
        }

        $sensor->loadMissing('sensorType');
        $channelKey = $channelKey ?: $sensor->sensorType->code;
        $installedAt ??= CarbonImmutable::now();

        try {
            return DB::transaction(function () use ($device, $sensor, $channelKey, $installedAt) {
                $channel = DeviceChannel::where('device_id', $device->id)->where('channel_key', $channelKey)->lockForUpdate()->first();

                if ($channel && $channel->sensor_type_id !== $sensor->sensor_type_id) {
                    throw ApiException::unprocessable(
                        ErrorCode::SENSOR_TYPE_MISMATCH,
                        "Channel '{$channelKey}' bertipe berbeda dengan sensor {$sensor->serial_number}.",
                    );
                }

                $channel ??= DeviceChannel::create([
                    'device_id' => $device->id,
                    'sensor_type_id' => $sensor->sensor_type_id,
                    'channel_key' => $channelKey,
                    'label' => $sensor->sensorType->name,
                ]);

                $sensorBusy = SensorInstallation::where('sensor_id', $sensor->id)
                    ->where(fn ($q) => $q->whereNull('removed_at')->orWhere('removed_at', '>', $installedAt))
                    ->exists();
                if ($sensorBusy) {
                    throw ApiException::conflict(ErrorCode::SENSOR_ALREADY_INSTALLED, "Sensor {$sensor->serial_number} masih terpasang di tempat lain.");
                }

                $channelBusy = SensorInstallation::where('device_channel_id', $channel->id)
                    ->where(fn ($q) => $q->whereNull('removed_at')->orWhere('removed_at', '>', $installedAt))
                    ->exists();
                if ($channelBusy) {
                    throw ApiException::conflict(ErrorCode::CHANNEL_OCCUPIED, "Channel '{$channelKey}' sudah terisi sensor lain.");
                }

                $installation = SensorInstallation::create([
                    'sensor_id' => $sensor->id,
                    'device_channel_id' => $channel->id,
                    'installed_at' => $installedAt,
                ]);

                return $installation->load(['sensor.sensorType', 'channel.device']);
            });
        } catch (QueryException $e) {
            throw $this->translateExclusion($e) ?? $e;
        }
    }

    public function uninstall(Device $device, Sensor $sensor, ?CarbonImmutable $removedAt, string $reason): SensorInstallation
    {
        $installation = SensorInstallation::where('sensor_id', $sensor->id)
            ->whereNull('removed_at')
            ->whereIn('device_channel_id', DeviceChannel::where('device_id', $device->id)->select('id'))
            ->first();

        if (! $installation) {
            throw ApiException::notFound("Sensor {$sensor->serial_number} tidak sedang terpasang di device {$device->code}.");
        }

        $removedAt ??= CarbonImmutable::now();

        if ($removedAt->lessThanOrEqualTo($installation->installed_at)) {
            throw ApiException::unprocessable(ErrorCode::VALIDATION_FAILED, 'removed_at harus setelah installed_at.', [[
                'field' => 'removed_at', 'code' => 'OUT_OF_BOUNDS', 'message' => 'removed_at harus setelah installed_at.',
            ]]);
        }

        $installation->update(['removed_at' => $removedAt, 'removal_reason' => $reason]);

        return $installation->load(['sensor.sensorType', 'channel.device']);
    }

    private function translateExclusion(QueryException $e): ?ApiException
    {
        if (($e->errorInfo[0] ?? null) !== '23P01') {
            return null;
        }

        $msg = $e->getMessage();
        if (str_contains($msg, 'si_channel_no_overlap')) {
            return ApiException::conflict(ErrorCode::CHANNEL_OCCUPIED, 'Channel sudah terisi sensor lain pada rentang waktu tersebut.');
        }

        return ApiException::conflict(ErrorCode::SENSOR_ALREADY_INSTALLED, 'Sensor sudah terpasang di tempat lain pada rentang waktu tersebut.');
    }
}
