<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use App\Support\ErrorCode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class GuardIngestPayload
{
    public function handle(Request $request, Closure $next): Response
    {
        $len = $request->header('Content-Length');
        if ($len && (int) $len > 1048576) {
            return ApiResponse::error(413, ErrorCode::PAYLOAD_TOO_LARGE, 'Ukuran payload melebihi batas 1 MB.');
        }

        $raw = $request->getContent();
        if ($raw !== '' && $raw !== null) {
            json_decode($raw);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return ApiResponse::error(400, ErrorCode::MALFORMED_JSON, 'Format JSON tidak valid: '.json_last_error_msg().'.');
            }
        }

        return $next($request);
    }
}
