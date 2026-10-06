<?php

namespace App\Models;

class SensorType extends BaseModel
{
    protected function casts(): array
    {
        return [
            'min_value' => 'float',
            'max_value' => 'float',
            'precision' => 'integer',
            'counter_factor' => 'float',
            'error_codes' => 'array',
        ];
    }

    public function displayUnit(): string
    {
        return $this->kind === 'counter' && $this->derived_unit ? $this->derived_unit : $this->unit;
    }
}
