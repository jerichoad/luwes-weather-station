<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Location\StoreLocationRequest;
use App\Http\Requests\Location\UpdateLocationRequest;
use App\Models\Device;
use App\Models\Location;
use App\Support\ApiException;
use App\Support\ApiResponse;
use App\Support\ErrorCode;
use App\Support\Time;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LocationController
{
    public function index(Request $request): JsonResponse
    {
        $q = Location::query();
        if ($s = $request->query('q')) {
            $q->where(fn ($w) => $w->where('code', 'ILIKE', "%{$s}%")->orWhere('name', 'ILIKE', "%{$s}%"));
        }
        $perPage = min((int) ($request->query('per_page', 20)), 100);

        return ApiResponse::paginated($q->orderBy('id')->paginate($perPage), fn (Location $l) => [
            'id' => $l->id, 'code' => $l->code, 'name' => $l->name, 'address' => $l->address,
            'latitude' => $l->latitude, 'longitude' => $l->longitude, 'altitude_m' => $l->altitude_m,
        ]);
    }

    public function store(StoreLocationRequest $request): JsonResponse
    {
        $l = Location::create($request->validated());

        return ApiResponse::created(['id' => $l->id, 'code' => $l->code, 'name' => $l->name]);
    }

    public function show(int $location): JsonResponse
    {
        $l = Location::findOrFail($location);

        return ApiResponse::ok([
            'id' => $l->id, 'code' => $l->code, 'name' => $l->name, 'address' => $l->address,
            'latitude' => $l->latitude, 'longitude' => $l->longitude, 'altitude_m' => $l->altitude_m,
            'created_at' => Time::iso($l->created_at), 'updated_at' => Time::iso($l->updated_at),
        ]);
    }

    public function update(int $location, UpdateLocationRequest $request): JsonResponse
    {
        $l = Location::findOrFail($location);
        $l->update($request->validated());

        return $this->show($l->id);
    }

    public function destroy(int $location): JsonResponse
    {
        $l = Location::findOrFail($location);
        if (Device::withTrashed()->where('location_id', $l->id)->exists()) {
            throw ApiException::conflict(ErrorCode::RESOURCE_IN_USE, 'Lokasi masih digunakan oleh device.');
        }
        $l->delete();

        return ApiResponse::ok(null);
    }
}
