<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceStatusHistory extends BaseModel
{
    protected $table = 'device_status_history';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['changed_at' => 'immutable_datetime'];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
