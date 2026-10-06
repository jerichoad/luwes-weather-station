<?php

namespace App\Http\Requests\Ingest;

use App\Support\ApiException;
use App\Support\ErrorCode;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreHeartbeatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'device_id' => 'required|string|max:32',
            'ts' => 'required|integer|min:1577836800',
            'fw' => 'required|string|max:32',
            'battery_v' => 'nullable|numeric|between:0,10',
            'rssi' => 'nullable|integer|between:-150,0',
            'uptime_s' => 'required|integer|min:0',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new ApiException(422, ErrorCode::VALIDATION_FAILED, 'Payload tidak valid.', ErrorCode::detailsFromValidator($validator));
    }
}
