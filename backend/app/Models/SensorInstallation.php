<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SensorInstallation extends BaseModel
{
    protected function casts(): array
    {
        return [
            'installed_at' => 'immutable_datetime',
            'removed_at' => 'immutable_datetime',
        ];
    }

    public function sensor(): BelongsTo
    {
        return $this->belongsTo(Sensor::class)->withTrashed();
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(DeviceChannel::class, 'device_channel_id');
    }
}
