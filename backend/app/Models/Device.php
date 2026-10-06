<?php

namespace App\Models;

use App\Domain\Devices\Connectivity;
use App\Domain\Devices\DeviceStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Device extends BaseModel
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'status' => DeviceStatus::class,
            'last_seen_at' => 'immutable_datetime',
            'last_telemetry_received_at' => 'immutable_datetime',
            'last_reading_at' => 'immutable_datetime',
            'last_boot_at' => 'immutable_datetime',
            'commissioned_at' => 'immutable_datetime',
            'decommissioned_at' => 'immutable_datetime',
            'last_battery_v' => 'float',
            'last_rssi' => 'integer',
            'last_uptime_s' => 'integer',
            'clock_offset_s' => 'integer',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class)->withTrashed();
    }

    public function channels(): HasMany
    {
        return $this->hasMany(DeviceChannel::class)->orderBy('id');
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(DeviceCredential::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(DeviceStatusHistory::class)->orderByDesc('changed_at')->orderByDesc('id');
    }

    public function connectivity(?CarbonImmutable $now = null): string
    {
        return Connectivity::fromLastSeen(
            $this->last_seen_at,
            $now ?? CarbonImmutable::now(),
            (int) config('weather.connectivity.online_s'),
            (int) config('weather.connectivity.offline_s'),
        );
    }
}
