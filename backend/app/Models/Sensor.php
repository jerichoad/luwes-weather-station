<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Sensor extends BaseModel
{
    use SoftDeletes;

    protected function casts(): array
    {
        return ['retired_at' => 'immutable_datetime'];
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

    public function calibrations(): HasMany
    {
        return $this->hasMany(SensorCalibration::class)->orderByDesc('effective_from');
    }
}
