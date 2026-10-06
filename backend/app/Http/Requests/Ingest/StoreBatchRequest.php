<?php

namespace App\Http\Requests\Ingest;

use App\Support\ApiException;
use App\Support\ErrorCode;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'device_id' => 'required|string|max:32',
            'fw' => 'required|string|max:32',
            'batch' => 'required|array|min:1|max:500',
            'batch.*' => 'array',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        if (is_array($this->input('batch')) && count($this->input('batch')) > 500) {
            throw new ApiException(422, ErrorCode::BATCH_TOO_LARGE, 'Batch terlalu besar, maksimum 500 item per request.');
        }

        throw new ApiException(422, ErrorCode::VALIDATION_FAILED, 'Payload tidak valid.', ErrorCode::detailsFromValidator($validator));
    }
}
