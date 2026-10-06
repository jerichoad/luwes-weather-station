<?php

use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\DeviceCredentialController;
use App\Http\Controllers\Api\V1\DeviceHealthController;
use App\Http\Controllers\Api\V1\DeviceSensorController;
use App\Http\Controllers\Api\V1\Ingest\HeartbeatController;
use App\Http\Controllers\Api\V1\Ingest\TelemetryController;
use App\Http\Controllers\Api\V1\LocationController;
use App\Http\Controllers\Api\V1\ReadingController;
use App\Http\Controllers\Api\V1\SensorCalibrationController;
use App\Http\Controllers\Api\V1\SensorController;
use App\Http\Controllers\Api\V1\SensorTypeController;
use App\Http\Middleware\AuthenticateDevice;
use App\Http\Middleware\GuardIngestPayload;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::prefix('ingest')->middleware([
        'throttle:ingest-ip',
        GuardIngestPayload::class,
        AuthenticateDevice::class,
        'throttle:ingest-device',
    ])->group(function () {
        Route::post('/telemetry', [TelemetryController::class, 'single']);
        Route::post('/telemetry/batch', [TelemetryController::class, 'batch']);
        Route::post('/heartbeat', HeartbeatController::class);
    });

    Route::apiResource('locations', LocationController::class);

    Route::get('/devices', [DeviceController::class, 'index']);
    Route::post('/devices', [DeviceController::class, 'store']);
    Route::get('/devices/{device}', [DeviceController::class, 'show']);
    Route::patch('/devices/{device}', [DeviceController::class, 'update']);
    Route::delete('/devices/{device}', [DeviceController::class, 'destroy']);
    Route::post('/devices/{device}/credentials/rotate', [DeviceCredentialController::class, 'rotate']);
    Route::get('/devices/{device}/health', [DeviceHealthController::class, 'show']);

    Route::get('/devices/{device}/sensors', [DeviceSensorController::class, 'index']);
    Route::post('/devices/{device}/sensors', [DeviceSensorController::class, 'store']);
    Route::delete('/devices/{device}/sensors/{sensor}', [DeviceSensorController::class, 'destroy']);

    Route::apiResource('sensor-types', SensorTypeController::class)->only(['index', 'store', 'update']);

    Route::apiResource('sensors', SensorController::class);
    Route::get('/sensors/{sensor}/calibrations', [SensorCalibrationController::class, 'index']);
    Route::post('/sensors/{sensor}/calibrations', [SensorCalibrationController::class, 'store']);

    Route::get('/devices/{device}/readings/latest', [ReadingController::class, 'latest']);
    Route::get('/readings', [ReadingController::class, 'index']);
    Route::get('/readings/summary', [ReadingController::class, 'summary']);

    Route::get('/dashboard/overview', [DashboardController::class, 'overview']);
});
