<?php

namespace App\Http\Requests\Sensor;

use App\Support\ApiException;
use App\Support\ErrorCode;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreSensorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'serial_number' => 'required|string|max:64|unique:sensors,serial_number',
            'sensor_type' => 'required|string|exists:sensor_types,code',
            'manufacturer' => 'nullable|string|max:100',
            'model' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new ApiException(422, ErrorCode::VALIDATION_FAILED, 'Payload tidak valid.', ErrorCode::detailsFromValidator($validator));
    }
}
