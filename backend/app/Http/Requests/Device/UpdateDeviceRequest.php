<?php

namespace App\Http\Requests\Device;

use App\Support\ApiException;
use App\Support\ErrorCode;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:150',
            'location_id' => 'sometimes|exists:locations,id',
            'status' => 'sometimes|string|in:provisioned,active,maintenance,decommissioned',
            'status_reason' => 'required_with:status|string|max:255',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new ApiException(422, ErrorCode::VALIDATION_FAILED, 'Payload tidak valid.', ErrorCode::detailsFromValidator($validator));
    }
}
