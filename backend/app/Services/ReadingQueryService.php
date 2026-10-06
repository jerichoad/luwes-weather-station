<?php

namespace App\Services;

use App\Domain\Rain\RainCalculator;
use App\Models\Device;
use App\Models\DeviceChannel;
use App\Support\ApiException;
use App\Support\ErrorCode;
use App\Support\Time;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReadingQueryService
{
    public const INTERVALS = ['raw' => 60, '1m' => 60, '1h' => 3600, '1d' => 86400];

    private const AUTO_ORDER = ['1m', '1h', '1d'];

    /**
     * @param  array{device_id:int, sensor_types:list<string>, from:CarbonImmutable, to:CarbonImmutable, interval:?string, agg:?string, include_flagged:bool, cursor:?int, limit:?int}  $q
     */
    public function timeSeries(array $q): array
    {
        $maxPoints = (int) config('weather.readings.max_points', 2000);
        $from = $q['from'];
        $to = $q['to'];
        $rangeS = $to->getTimestamp() - $from->getTimestamp();

        $interval = $q['interval'] ?? $this->autoInterval($rangeS, $maxPoints);
        $points = (int) ceil($rangeS / self::INTERVALS[$interval]);

        if ($points > $maxPoints) {
            $suggested = $this->autoInterval($rangeS, $maxPoints);
            throw ApiException::unprocessable(ErrorCode::RANGE_TOO_LARGE, "Rentang terlalu besar untuk interval {$interval} ({$points} titik, maks {$maxPoints}).", [[
                'field' => 'interval',
                'code' => ErrorCode::RANGE_TOO_LARGE,
                'message' => "Gunakan interval {$suggested} atau perkecil rentang.",
                'suggested_interval' => $suggested,
            ]]);
        }

        $channels = DeviceChannel::with('sensorType')
            ->where('device_id', $q['device_id'])
            ->whereHas('sensorType', fn ($w) => $w->whereIn('code', $q['sensor_types']))
            ->orderBy('id')
            ->get();

        $agg = $q['agg'];
        if ($agg === 'sum' && $channels->contains(fn ($c) => $c->sensorType->kind !== 'counter')) {
            throw ApiException::unprocessable(ErrorCode::INVALID_AGGREGATION, 'agg=sum hanya untuk sensor bertipe counter.', [[
                'field' => 'agg', 'code' => ErrorCode::INVALID_AGGREGATION, 'message' => 'agg=sum hanya untuk sensor bertipe counter.',
            ]]);
        }

        $limit = min($q['limit'] ?? $maxPoints, $maxPoints);
        $effectiveFrom = $from->getTimestamp();
        if ($q['cursor'] !== null) {
            $effectiveFrom = max($effectiveFrom, intdiv($q['cursor'], 1000) + 1);
        }

        $seriesData = [];
        foreach ($channels as $ch) {
            $type = $ch->sensorType;
            $seriesAgg = $type->kind === 'counter' ? 'sum' : ($agg ?? ($type->default_agg === 'sum' ? 'avg' : $type->default_agg));

            $rows = match ($interval) {
                'raw' => $this->fetchRaw($ch, $effectiveFrom, $to->getTimestamp(), $q['include_flagged'], $limit + 1),
                '1m' => $this->fetchMinute($ch, $seriesAgg, $effectiveFrom, $to->getTimestamp(), $limit + 1),
                default => $this->fetchAggregate($ch, $interval, $seriesAgg, $effectiveFrom, $to->getTimestamp(), $limit + 1),
            };

            $seriesData[] = ['channel' => $ch, 'agg' => $seriesAgg, 'rows' => $rows];
        }

        $allTs = [];
        foreach ($seriesData as $s) {
            foreach ($s['rows'] as $t => $_) {
                $allTs[$t] = true;
            }
        }
        ksort($allTs);
        $timestamps = array_keys($allTs);

        $nextCursor = null;
        if (count($timestamps) > $limit) {
            $timestamps = array_slice($timestamps, 0, $limit);
            $nextCursor = end($timestamps) * 1000;
        }

        $series = [];
        foreach ($seriesData as $s) {
            $ch = $s['channel'];
            $type = $ch->sensorType;
            $precision = $type->kind === 'counter' ? 2 : $type->precision;
            $values = [];
            $flags = [];
            foreach ($timestamps as $t) {
                $row = $s['rows'][$t] ?? null;
                $values[] = $row === null || $row['value'] === null ? null : round($row['value'], $precision);
                if ($q['include_flagged'] && $interval === 'raw') {
                    $flags[] = $row['flags'] ?? null;
                }
            }

            $entry = [
                'channel_id' => $ch->id,
                'channel_key' => $ch->channel_key,
                'label' => $ch->label,
                'sensor_type' => $type->code,
                'unit' => $type->displayUnit(),
                'precision' => $precision,
                'agg' => $s['agg'],
                'values' => $values,
            ];
            if ($q['include_flagged'] && $interval === 'raw') {
                $entry['quality_flags'] = $flags;
            }
            $series[] = $entry;
        }

        return [
            'data' => [
                'device_id' => $q['device_id'],
                'interval' => $interval,
                'agg' => $agg ?? 'default',
                'timestamps' => array_map(fn ($t) => $t * 1000, $timestamps),
                'series' => $series,
            ],
            'meta' => [
                'from' => $from->toIso8601ZuluString(),
                'to' => $to->toIso8601ZuluString(),
                'interval_seconds' => self::INTERVALS[$interval],
                'points' => count($timestamps),
                'max_points' => $maxPoints,
                'next_cursor' => $nextCursor,
            ],
        ];
    }

    private function autoInterval(int $rangeS, int $maxPoints): string
    {
        foreach (self::AUTO_ORDER as $iv) {
            if (ceil($rangeS / self::INTERVALS[$iv]) <= $maxPoints) {
                return $iv;
            }
        }

        return '1d';
    }

    /** @return array<int, array{value:?float, flags:int}> */
    private function fetchRaw(DeviceChannel $ch, int $from, int $to, bool $includeFlagged, int $limit): array
    {
        if ($ch->sensorType->kind === 'counter') {
            return $this->counterPerReading($ch, $from, $to, $limit);
        }

        $filter = $includeFlagged ? '' : 'AND quality_flags = 0 AND value IS NOT NULL';
        $rows = DB::select(
            "SELECT extract(epoch FROM time)::bigint AS t, value, quality_flags
             FROM sensor_readings WHERE channel_id = ? AND time >= ? AND time < ? {$filter}
             ORDER BY time LIMIT ?",
            [$ch->id, Time::db($from), Time::db($to), $limit]
        );

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->t] = ['value' => $r->value !== null ? (float) $r->value : null, 'flags' => (int) $r->quality_flags];
        }

        return $out;
    }

    /** @return array<int, array{value:?float, flags:int}> */
    private function fetchMinute(DeviceChannel $ch, string $agg, int $from, int $to, int $limit): array
    {
        $kind = $ch->sensorType->kind;

        if ($kind === 'counter') {
            return $this->counterPerReading($ch, $from, $to, $limit, true);
        }

        $expr = match (true) {
            $kind === 'angle' && $agg === 'avg' => 'MOD((degrees(atan2(SUM(sin(radians(value))), SUM(cos(radians(value))))) + 360)::numeric, 360)::float8',
            $agg === 'min' => 'MIN(value)',
            $agg === 'max' => 'MAX(value)',
            default => 'AVG(value)',
        };

        $rows = DB::select(
            "SELECT extract(epoch FROM date_trunc('minute', time))::bigint AS t, {$expr} AS v
             FROM sensor_readings
             WHERE channel_id = ? AND time >= ? AND time < ? AND quality_flags = 0 AND value IS NOT NULL
             GROUP BY 1 ORDER BY 1 LIMIT ?",
            [$ch->id, Time::db($from), Time::db($to), $limit]
        );

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->t] = ['value' => $r->v !== null ? (float) $r->v : null, 'flags' => 0];
        }

        return $out;
    }

    /**
     * Curah hujan (mm) per bacaan dari selisih counter, dengan deteksi reset.
     *
     * @return array<int, array{value:?float, flags:int}>
     */
    private function counterPerReading(DeviceChannel $ch, int $from, int $to, int $limit, bool $bucketMinute = false): array
    {
        $rows = DB::select(
            'SELECT extract(epoch FROM r.time)::bigint AS t, r.value, p.seq
             FROM sensor_readings r LEFT JOIN device_packets p ON p.device_id = r.device_id AND p.time = r.time
             WHERE r.channel_id = ? AND r.time >= ? AND r.time < ? AND r.quality_flags = 0 AND r.value IS NOT NULL
             ORDER BY r.time LIMIT ?',
            [$ch->id, Time::db($from), Time::db($to), $limit]
        );

        $prev = DB::selectOne(
            'SELECT extract(epoch FROM r.time)::bigint AS t, r.value, p.seq
             FROM sensor_readings r LEFT JOIN device_packets p ON p.device_id = r.device_id AND p.time = r.time
             WHERE r.channel_id = ? AND r.time < ? AND r.time >= ? AND r.quality_flags = 0 AND r.value IS NOT NULL
             ORDER BY r.time DESC LIMIT 1',
            [$ch->id, Time::db($from), Time::db($from - 86400)]
        );

        $points = array_map(fn ($r) => ['time' => (int) $r->t, 'counter' => (float) $r->value, 'seq' => (int) ($r->seq ?? 0)], $rows);
        $previous = $prev ? ['time' => (int) $prev->t, 'counter' => (float) $prev->value, 'seq' => (int) ($prev->seq ?? 0)] : null;
        $deltas = RainCalculator::deltasMm($points, $previous, (float) ($ch->sensorType->counter_factor ?? 0.2));

        $out = [];
        foreach ($points as $i => $p) {
            $t = $bucketMinute ? intdiv($p['time'], 60) * 60 : $p['time'];
            $out[$t] = ['value' => ($out[$t]['value'] ?? 0) + $deltas[$i], 'flags' => 0];
        }

        return $out;
    }

    /** @return array<int, array{value:?float, flags:int}> */
    private function fetchAggregate(DeviceChannel $ch, string $interval, string $agg, int $from, int $to, int $limit): array
    {
        $alignedFrom = $interval === '1d' ? Time::wibDayStart($from) : Time::hourStart($from);

        $col = match (true) {
            $ch->sensorType->kind === 'counter' => 'sum_value',
            $agg === 'min' => 'min_value',
            $agg === 'max' => 'max_value',
            default => 'avg_value',
        };

        $goodFilter = $ch->sensorType->kind === 'counter' ? '' : 'AND good_count > 0';

        $rows = DB::select(
            "SELECT extract(epoch FROM bucket_start)::bigint AS t, {$col} AS v
             FROM reading_aggregates
             WHERE channel_id = ? AND bucket_interval = ? AND bucket_start >= ? AND bucket_start < ? {$goodFilter}
             ORDER BY bucket_start LIMIT ?",
            [$ch->id, $interval, Time::db($alignedFrom), Time::db($to), $limit]
        );

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->t] = ['value' => $r->v !== null ? (float) $r->v : null, 'flags' => 0];
        }

        return $out;
    }

    /**
     * Nilai terkini tiap channel device.
     *
     * @return list<array<string, mixed>>
     */
    public function latest(Device $device): array
    {
        $channels = DeviceChannel::with(['sensorType', 'activeInstallation.sensor'])
            ->where('device_id', $device->id)->orderBy('id')->get();

        $latest = $this->latestByChannel($channels->pluck('id')->all());
        $rainToday = $this->rainToday($channels->filter(fn ($c) => $c->sensorType->kind === 'counter')->pluck('id')->all());

        return $channels->map(function (DeviceChannel $ch) use ($latest, $rainToday) {
            $type = $ch->sensorType;
            $l = $latest[$ch->id] ?? null;
            $item = [
                'channel_id' => $ch->id,
                'channel_key' => $ch->channel_key,
                'label' => $ch->label,
                'sensor_type' => $type->code,
                'unit' => $type->unit,
                'precision' => $type->precision,
                'value' => $l ? round($l['value'], $type->precision) : null,
                'raw_value' => $l['raw_value'] ?? null,
                'time' => $l ? Time::iso(Time::parse($l['time'])) : null,
                'sensor' => $ch->activeInstallation?->sensor ? [
                    'id' => $ch->activeInstallation->sensor->id,
                    'serial_number' => $ch->activeInstallation->sensor->serial_number,
                ] : null,
            ];
            if ($type->kind === 'counter') {
                $item['rain_today_mm'] = round($rainToday[$ch->id] ?? 0.0, 2);
                $item['rain_today_unit'] = $type->derived_unit ?? 'mm';
            }

            return $item;
        })->values()->all();
    }

    /**
     * @param  list<int>  $channelIds
     * @return array<int, array{value:float, raw_value:float, time:int}>
     */
    public function latestByChannel(array $channelIds, int $lookbackS = 7 * 86400): array
    {
        if ($channelIds === []) {
            return [];
        }

        $in = implode(',', array_fill(0, count($channelIds), '?'));
        $since = Time::db(time() - $lookbackS);

        $rows = DB::select(
            "SELECT DISTINCT ON (channel_id) channel_id, extract(epoch FROM time)::bigint AS t, value, raw_value
             FROM sensor_readings
             WHERE channel_id IN ({$in}) AND time >= ? AND quality_flags = 0 AND value IS NOT NULL
             ORDER BY channel_id, time DESC",
            array_merge($channelIds, [$since])
        );

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->channel_id] = ['value' => (float) $r->value, 'raw_value' => (float) $r->raw_value, 'time' => (int) $r->t];
        }

        return $out;
    }

    /**
     * @param  list<int>  $channelIds
     * @return array<int, float>
     */
    private function rainToday(array $channelIds): array
    {
        if ($channelIds === []) {
            return [];
        }

        $dayStart = Time::wibDayStart(time());
        $in = implode(',', array_fill(0, count($channelIds), '?'));
        $rows = DB::select(
            "SELECT channel_id, SUM(sum_value) AS mm FROM reading_aggregates
             WHERE channel_id IN ({$in}) AND bucket_interval = '1h' AND bucket_start >= ?
             GROUP BY channel_id",
            array_merge($channelIds, [Time::db($dayStart)])
        );

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->channel_id] = (float) $r->mm;
        }

        return $out;
    }

    /**
     * Ringkasan harian WIB berbasis agregat 1d.
     *
     * @return array{items: list<array<string, mixed>>, total:int}
     */
    public function dailySummary(Device $device, CarbonImmutable $fromDate, CarbonImmutable $toDate, int $page, int $perPage): array
    {
        $dates = [];
        for ($d = $fromDate; $d->lessThanOrEqualTo($toDate); $d = $d->addDay()) {
            $dates[] = $d->format('Y-m-d');
        }
        $total = count($dates);
        $pageDates = array_slice($dates, ($page - 1) * $perPage, $perPage);

        if ($pageDates === []) {
            return ['items' => [], 'total' => $total];
        }

        $channels = DeviceChannel::with('sensorType')->where('device_id', $device->id)->orderBy('id')->get();
        $firstOf = fn (string $code) => $channels->first(fn ($c) => $c->sensorType->code === $code);
        $rainIds = $channels->filter(fn ($c) => $c->sensorType->kind === 'counter')->pluck('id')->all();

        $byChannelDay = $this->dailyAggregates(
            $channels->pluck('id')->all(),
            $this->wibDateToEpoch($pageDates[0]),
            $this->wibDateToEpoch(end($pageDates)) + 86400,
        );

        $temp = $firstOf('temp_air');
        $hum = $firstOf('humidity');
        $wind = $firstOf('wind_speed');

        $items = [];
        foreach ($pageDates as $date) {
            $start = $this->wibDateToEpoch($date);
            $t = $temp ? ($byChannelDay[$temp->id][$start] ?? null) : null;
            $h = $hum ? ($byChannelDay[$hum->id][$start] ?? null) : null;
            $w = $wind ? ($byChannelDay[$wind->id][$start] ?? null) : null;

            $rain = null;
            foreach ($rainIds as $rid) {
                if (isset($byChannelDay[$rid][$start])) {
                    $rain = ($rain ?? 0) + (float) $byChannelDay[$rid][$start]->sum_value;
                }
            }

            $items[] = [
                'date' => $date,
                'temp_air' => $t ? [
                    'min' => $this->r($t->min_value, 1), 'max' => $this->r($t->max_value, 1), 'avg' => $this->r($t->avg_value, 1),
                ] : null,
                'humidity' => $h ? ['avg' => $this->r($h->avg_value, 1)] : null,
                'rain_mm' => $rain !== null ? round($rain, 2) : null,
                'wind_speed_max' => $w ? $this->r($w->max_value, 1) : null,
                'coverage' => $t ? round(min(1, $t->good_count / 1440), 3) : 0.0,
            ];
        }

        return ['items' => $items, 'total' => $total];
    }

    private function r(mixed $v, int $p): ?float
    {
        return $v === null ? null : round((float) $v, $p);
    }

    public function wibDateToEpoch(string $date): int
    {
        return CarbonImmutable::createFromFormat('Y-m-d H:i:s', $date.' 00:00:00', 'Asia/Jakarta')->getTimestamp();
    }

    /**
     * @param  list<int>  $channelIds
     * @return array<int, array<int, object>>
     */
    private function dailyAggregates(array $channelIds, int $from, int $to): array
    {
        if ($channelIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($channelIds), '?'));
        $rows = DB::select(
            "SELECT channel_id, extract(epoch FROM bucket_start)::bigint AS t, good_count, avg_value, min_value, max_value, sum_value
             FROM reading_aggregates
             WHERE channel_id IN ({$in}) AND bucket_interval = '1d' AND bucket_start >= ? AND bucket_start < ?",
            array_merge($channelIds, [Time::db($from), Time::db($to)])
        );

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->channel_id][(int) $r->t] = $r;
        }

        return $out;
    }

    /**
     * @param  Collection<int, Device>  $devices
     * @return array<int, array<string, array{value:float, unit:string, time:string}>>
     */
    public function latestForDevices(Collection $devices): array
    {
        $channels = DeviceChannel::with('sensorType')->whereIn('device_id', $devices->pluck('id'))->get();
        $latest = $this->latestByChannel($channels->pluck('id')->all(), 86400);

        $out = [];
        foreach ($channels as $ch) {
            if (! isset($latest[$ch->id])) {
                continue;
            }
            $l = $latest[$ch->id];
            $out[$ch->device_id][$ch->channel_key] = [
                'value' => round($l['value'], $ch->sensorType->precision),
                'unit' => $ch->sensorType->unit,
                'time' => Time::iso(Time::parse($l['time'])),
            ];
        }

        return $out;
    }
}
