<?php

namespace App\Http\Middleware;

use App\Domain\Devices\DeviceKeyService;
use App\Domain\Devices\DeviceStatus;
use App\Models\Device;
use App\Models\DeviceCredential;
use App\Support\ApiResponse;
use App\Support\ErrorCode;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $request->bearerToken();
        $deviceCode = $request->input('device_id');

        if (! $apiKey || ! $deviceCode) {
            return $this->fail($request, 'Missing device credentials.');
        }

        $device = Device::where('code', $deviceCode)->first();

        if (! $device) {
            return $this->fail($request, "Device '{$deviceCode}' not found.");
        }

        if ($device->status === DeviceStatus::Decommissioned) {
            return ApiResponse::error(403, ErrorCode::DEVICE_DECOMMISSIONED, 'Device ini sudah di-decommission.', [], ['Retry-After' => '0']);
        }

        $keyService = new DeviceKeyService(config('weather.device_key_pepper'));
        $credentials = DeviceCredential::where('device_id', $device->id)->active()->get();

        $matched = null;
        foreach ($credentials as $cred) {
            if ($keyService->matches($apiKey, $cred->key_hash)) {
                $matched = $cred;
                break;
            }
        }

        if (! $matched) {
            return $this->fail($request, "Invalid API key for device '{$deviceCode}'.");
        }

        if (! Cache::has("cred-used:{$matched->id}")) {
            Cache::put("cred-used:{$matched->id}", 1, 60);
            $matched->update(['last_used_at' => now()]);
        }

        $request->attributes->set('device', $device);
        Log::withContext(['device_code' => $device->code, 'device_id' => $device->id]);

        return $next($request);
    }

    private function fail(Request $request, string $logMessage): Response
    {
        Log::warning('Device auth failed', ['ip' => $request->ip(), 'message' => $logMessage]);

        return ApiResponse::error(401, ErrorCode::INVALID_DEVICE_CREDENTIALS, 'Credential device tidak valid.');
    }
}
