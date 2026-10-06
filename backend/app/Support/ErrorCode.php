<?php

namespace App\Support;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Str;

final class ErrorCode
{
    public const VALIDATION_FAILED = 'VALIDATION_FAILED';

    public const MALFORMED_JSON = 'MALFORMED_JSON';

    public const INVALID_DEVICE_CREDENTIALS = 'INVALID_DEVICE_CREDENTIALS';

    public const DEVICE_DECOMMISSIONED = 'DEVICE_DECOMMISSIONED';

    public const FORBIDDEN = 'FORBIDDEN';

    public const NOT_FOUND = 'NOT_FOUND';

    public const METHOD_NOT_ALLOWED = 'METHOD_NOT_ALLOWED';

    public const INVALID_STATUS_TRANSITION = 'INVALID_STATUS_TRANSITION';

    public const SENSOR_ALREADY_INSTALLED = 'SENSOR_ALREADY_INSTALLED';

    public const CHANNEL_OCCUPIED = 'CHANNEL_OCCUPIED';

    public const SENSOR_TYPE_MISMATCH = 'SENSOR_TYPE_MISMATCH';

    public const RESOURCE_IN_USE = 'RESOURCE_IN_USE';

    public const BATCH_TOO_LARGE = 'BATCH_TOO_LARGE';

    public const PAYLOAD_TOO_LARGE = 'PAYLOAD_TOO_LARGE';

    public const RANGE_TOO_LARGE = 'RANGE_TOO_LARGE';

    public const INVALID_AGGREGATION = 'INVALID_AGGREGATION';

    public const TIMESTAMP_INVALID = 'TIMESTAMP_INVALID';

    public const RATE_LIMITED = 'RATE_LIMITED';

    public const SERVICE_UNAVAILABLE = 'SERVICE_UNAVAILABLE';

    public const INTERNAL_ERROR = 'INTERNAL_ERROR';

    public const W_CHANNEL_NOT_MAPPED = 'CHANNEL_NOT_MAPPED';

    public const W_DUPLICATE_CHANNEL_IN_PACKET = 'DUPLICATE_CHANNEL_IN_PACKET';

    public const W_SENSOR_ERROR_CODE = 'SENSOR_ERROR_CODE';

    public const W_OUT_OF_RANGE = 'OUT_OF_RANGE';

    public const W_CLOCK_FUTURE = 'CLOCK_FUTURE';

    public const W_TS_COLLISION = 'TS_COLLISION';

    private const RULE_CODES = [
        'Required' => 'REQUIRED',
        'Present' => 'REQUIRED',
        'RequiredWith' => 'REQUIRED',
        'Numeric' => 'NOT_NUMERIC',
        'Integer' => 'NOT_INTEGER',
        'String' => 'NOT_STRING',
        'Array' => 'NOT_ARRAY',
        'Boolean' => 'NOT_BOOLEAN',
        'Date' => 'INVALID_DATE',
        'DateFormat' => 'INVALID_DATE',
        'Min' => 'OUT_OF_BOUNDS',
        'Max' => 'OUT_OF_BOUNDS',
        'Between' => 'OUT_OF_BOUNDS',
        'In' => 'INVALID_VALUE',
        'Exists' => 'NOT_FOUND',
        'Unique' => 'ALREADY_EXISTS',
        'Regex' => 'INVALID_FORMAT',
    ];

    /**
     * Ubah hasil validator Laravel menjadi details per field.
     *
     * @return list<array{field:string, code:string, message:string}>
     */
    public static function detailsFromValidator(Validator $validator, string $prefix = ''): array
    {
        $failed = $validator->failed();
        $messages = $validator->errors()->messages();
        $details = [];

        foreach ($messages as $field => $fieldMessages) {
            $rules = array_keys($failed[$field] ?? []);
            $rule = $rules[0] ?? null;
            $details[] = [
                'field' => $prefix.$field,
                'code' => self::codeFor($field, $rule),
                'message' => $fieldMessages[0],
            ];
        }

        return $details;
    }

    public static function codeFor(string $field, ?string $rule): string
    {
        if ($rule === null) {
            return 'INVALID';
        }

        if ($rule === 'Min' && preg_match('/(^|\.)ts$/', $field)) {
            return self::TIMESTAMP_INVALID;
        }

        return self::RULE_CODES[$rule] ?? strtoupper(Str::snake($rule));
    }
}
