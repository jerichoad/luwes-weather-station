<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Device;
use App\Services\DeviceService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class DeviceCredentialController
{
    public function rotate(int $device, DeviceService $svc): JsonResponse
    {
        $d = Device::findOrFail($device);

        return ApiResponse::created($svc->rotateKey($d));
    }
}
