<?php

namespace App\Domain\Ingestion;

use App\Support\Time;
use Illuminate\Support\Facades\DB;

/**
 * Snapshot channel, installation, dan kalibrasi sebuah device. Dimuat sekali per request,
 * lalu resolusi per bacaan dilakukan di memori.
 */
final class DeviceContext
{
    /**
     * @param  array<string, array{id:int, sensor_type:array{id:int, code:string, kind:string, min:float, max:float, precision:int, error_codes:list<float>, counter_factor:?float}}>  $channels  keyed by channel_key
     * @param  array<int, list<array{sensor_id:int, installed_at:int, removed_at:?int}>>  $installations  keyed by channel_id
     * @param  array<int, list<array{effective_from:int, scale_factor:float, offset_value:float}>>  $calibrations  keyed by sensor_id
     */
    public function __construct(
        public readonly int $deviceId,
        public readonly string $status,
        public readonly array $channels,
        public readonly array $installations,
        public readonly array $calibrations,
    ) {}

    public static function load(int $deviceId, string $status): self
    {
        $channelRows = DB::select(
            'SELECT c.id, c.channel_key, t.id AS type_id, t.code, t.kind, t.min_value, t.max_value, t.precision, t.error_codes, t.counter_factor
             FROM device_channels c JOIN sensor_types t ON t.id = c.sensor_type_id
             WHERE c.device_id = ? AND c.disabled_at IS NULL',
            [$deviceId]
        );

        $channels = [];
        $channelIds = [];
        foreach ($channelRows as $r) {
            $channelIds[] = (int) $r->id;
            $channels[$r->channel_key] = [
                'id' => (int) $r->id,
                'sensor_type' => [
                    'id' => (int) $r->type_id,
                    'code' => $r->code,
                    'kind' => $r->kind,
                    'min' => (float) $r->min_value,
                    'max' => (float) $r->max_value,
                    'precision' => (int) $r->precision,
                    'error_codes' => array_map('floatval', json_decode($r->error_codes, true) ?: []),
                    'counter_factor' => $r->counter_factor !== null ? (float) $r->counter_factor : null,
                ],
            ];
        }

        $installations = [];
        $sensorIds = [];
        if ($channelIds) {
            $in = implode(',', array_fill(0, count($channelIds), '?'));
            $rows = DB::select(
                "SELECT sensor_id, device_channel_id, installed_at, removed_at FROM sensor_installations WHERE device_channel_id IN ({$in})",
                $channelIds
            );
            foreach ($rows as $r) {
                $sensorIds[(int) $r->sensor_id] = true;
                $installations[(int) $r->device_channel_id][] = [
                    'sensor_id' => (int) $r->sensor_id,
                    'installed_at' => Time::epoch($r->installed_at),
                    'removed_at' => $r->removed_at ? Time::epoch($r->removed_at) : null,
                ];
            }
        }

        $calibrations = [];
        if ($sensorIds) {
            $ids = array_keys($sensorIds);
            $in = implode(',', array_fill(0, count($ids), '?'));
            $rows = DB::select(
                "SELECT sensor_id, scale_factor, offset_value, effective_from FROM sensor_calibrations WHERE sensor_id IN ({$in})",
                $ids
            );
            foreach ($rows as $r) {
                $calibrations[(int) $r->sensor_id][] = [
                    'effective_from' => Time::epoch($r->effective_from),
                    'scale_factor' => (float) $r->scale_factor,
                    'offset_value' => (float) $r->offset_value,
                ];
            }
        }

        return new self($deviceId, $status, $channels, $installations, $calibrations);
    }

    /** Sensor yang terpasang di channel pada waktu $t (range half-open [installed_at, removed_at)). */
    public function sensorAt(int $channelId, int $t): ?int
    {
        foreach ($this->installations[$channelId] ?? [] as $i) {
            if ($i['installed_at'] <= $t && ($i['removed_at'] === null || $t < $i['removed_at'])) {
                return $i['sensor_id'];
            }
        }

        return null;
    }
}
