<?php

namespace App\Http\Requests\Sensor;

use App\Support\ApiException;
use App\Support\ErrorCode;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class InstallSensorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sensor_id' => 'required|integer|exists:sensors,id',
            'channel_key' => 'nullable|string|max:32',
            'installed_at' => 'nullable|date',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new ApiException(422, ErrorCode::VALIDATION_FAILED, 'Payload tidak valid.', ErrorCode::detailsFromValidator($validator));
    }
}
