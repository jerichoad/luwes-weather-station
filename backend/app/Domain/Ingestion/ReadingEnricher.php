<?php

namespace App\Domain\Ingestion;

use App\Domain\Calibration\CalibrationApplier;
use App\Domain\Quality\QualityFlag;
use App\Domain\Quality\RangeValidator;
use App\Support\ErrorCode;

final class ReadingEnricher
{
    /**
     * Ubah satu paket ternormalisasi menjadi row sensor_readings.
     *
     * @param  array{ts:int, flags:int, readings:list<array{s:string, v:float}>}  $packet
     * @return array{
     *   rows: list<array{time:int, device_id:int, channel_id:int, sensor_id:int, raw_value:float, value:?float, quality_flags:int, kind:string}>,
     *   warnings: list<array{code:string, message:string}>,
     *   stored: int, flagged: int, skipped: int
     * }
     */
    public static function enrich(DeviceContext $ctx, array $packet): array
    {
        $t = $packet['ts'];
        $baseFlags = $packet['flags'] & QualityFlag::CLOCK_FUTURE;
        if ($ctx->status === 'maintenance') {
            $baseFlags |= QualityFlag::MAINTENANCE;
        }

        $rows = [];
        $warnings = [];
        $flagged = 0;
        $skipped = 0;

        foreach ($packet['readings'] as $r) {
            $s = $r['s'];
            $raw = (float) $r['v'];
            $channel = $ctx->channels[$s] ?? null;

            if ($channel === null) {
                $skipped++;
                $warnings[] = ['code' => ErrorCode::W_CHANNEL_NOT_MAPPED, 'message' => "Channel '{$s}' tidak terdaftar di device ini."];

                continue;
            }

            $sensorId = $ctx->sensorAt($channel['id'], $t);
            if ($sensorId === null) {
                $skipped++;
                $warnings[] = ['code' => ErrorCode::W_CHANNEL_NOT_MAPPED, 'message' => "Tidak ada sensor terpasang di channel '{$s}' pada waktu bacaan."];

                continue;
            }

            $type = $channel['sensor_type'];
            $flags = $baseFlags;

            if (RangeValidator::isErrorCode($raw, $type['error_codes'])) {
                $value = null;
                $flags |= QualityFlag::SENSOR_ERROR;
                $warnings[] = ['code' => ErrorCode::W_SENSOR_ERROR_CODE, 'message' => "Sensor '{$s}' mengirim kode error {$raw}."];
            } else {
                $value = CalibrationApplier::applyAt($raw, $ctx->calibrations[$sensorId] ?? [], $t);
                $value = round($value, max($type['precision'], 0) + 2);

                if (RangeValidator::isOutOfRange($value, $type['min'], $type['max'])) {
                    $flags |= QualityFlag::OUT_OF_RANGE;
                    $warnings[] = ['code' => ErrorCode::W_OUT_OF_RANGE, 'message' => "Nilai '{$s}' = {$value} di luar rentang {$type['min']}..{$type['max']}."];
                }
            }

            if ($flags !== 0) {
                $flagged++;
            }

            $rows[] = [
                'time' => $t,
                'device_id' => $ctx->deviceId,
                'channel_id' => $channel['id'],
                'sensor_id' => $sensorId,
                'raw_value' => $raw,
                'value' => $value,
                'quality_flags' => $flags,
                'kind' => $type['kind'],
            ];
        }

        return [
            'rows' => $rows,
            'warnings' => $warnings,
            'stored' => count($rows),
            'flagged' => $flagged,
            'skipped' => $skipped,
        ];
    }
}
