<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

final class ApiResponse
{
    public static function requestId(): string
    {
        $request = request();
        $id = $request?->attributes->get('request_id');

        if (! $id) {
            $id = (string) Str::ulid();
            $request?->attributes->set('request_id', $id);
        }

        return $id;
    }

    public static function ok(mixed $data, ?array $meta = null, int $status = 200, array $headers = []): JsonResponse
    {
        $body = ['data' => $data];
        if ($meta !== null) {
            $body['meta'] = $meta;
        }
        $body['request_id'] = self::requestId();

        return response()->json($body, $status, $headers, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    public static function created(mixed $data, ?array $meta = null): JsonResponse
    {
        return self::ok($data, $meta, 201);
    }

    public static function paginated(LengthAwarePaginator $paginator, callable $map, array $extraMeta = []): JsonResponse
    {
        return self::ok(
            array_map($map, $paginator->items()),
            array_merge([
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ], $extraMeta),
        );
    }

    public static function error(int $status, string $code, string $message, array $details = [], array $headers = []): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details,
            ],
            'request_id' => self::requestId(),
        ], $status, $headers, JSON_UNESCAPED_UNICODE);
    }

    public static function noContent(): JsonResponse
    {
        return self::ok(null);
    }
}
