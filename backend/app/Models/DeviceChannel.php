<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DeviceChannel extends BaseModel
{
    protected function casts(): array
    {
        return ['disabled_at' => 'immutable_datetime'];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function sensorType(): BelongsTo
    {
        return $this->belongsTo(SensorType::class);
    }

    public function installations(): HasMany
    {
        return $this->hasMany(SensorInstallation::class)->orderByDesc('installed_at');
    }

    public function activeInstallation(): HasOne
    {
        return $this->hasOne(SensorInstallation::class)->whereNull('removed_at');
    }
}
