<?php

namespace App\Http\Controllers\Api\V1;

use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class HealthController
{
    public function __invoke(): JsonResponse
    {
        $checks = [];
        try {
            DB::selectOne('SELECT 1');
            $checks['db'] = 'ok';
        } catch (\Throwable) {
            $checks['db'] = 'fail';
        }

        try {
            Redis::ping();
            $checks['redis'] = 'ok';
        } catch (\Throwable) {
            $checks['redis'] = 'fail';
        }

        $ok = ! in_array('fail', $checks, true);

        return ApiResponse::ok(array_merge(['status' => $ok ? 'ok' : 'degraded'], $checks), null, $ok ? 200 : 503);
    }
}
