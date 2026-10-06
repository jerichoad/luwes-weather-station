<?php

namespace App\Http\Requests\Reading;

use App\Support\ApiException;
use App\Support\ErrorCode;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class SummaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'device_id' => 'required|integer|exists:devices,id',
            'from' => 'required|date_format:Y-m-d',
            'to' => 'required|date_format:Y-m-d',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new ApiException(422, ErrorCode::VALIDATION_FAILED, 'Payload tidak valid.', ErrorCode::detailsFromValidator($validator));
    }
}
