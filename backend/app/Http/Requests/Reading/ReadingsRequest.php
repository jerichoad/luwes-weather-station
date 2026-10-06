<?php

namespace App\Http\Requests\Reading;

use App\Support\ApiException;
use App\Support\ErrorCode;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class ReadingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'device_id' => 'required|integer|exists:devices,id',
            'sensor_type' => 'required|string|max:128',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'interval' => 'nullable|string|in:raw,1m,1h,1d',
            'agg' => 'nullable|string|in:avg,min,max,sum',
            'include_flagged' => 'sometimes|boolean',
            'cursor' => 'nullable|integer',
            'limit' => 'nullable|integer|min:1|max:2000',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new ApiException(422, ErrorCode::VALIDATION_FAILED, 'Payload tidak valid.', ErrorCode::detailsFromValidator($validator));
    }
}
