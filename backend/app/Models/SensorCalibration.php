<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SensorCalibration extends BaseModel
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'scale_factor' => 'float',
            'offset_value' => 'float',
            'effective_from' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function sensor(): BelongsTo
    {
        return $this->belongsTo(Sensor::class);
    }
}
