<?php

namespace Database\Seeders;

use App\Domain\Devices\DeviceStatus;
use App\Models\Device;
use App\Models\Location;
use App\Models\SensorType;
use App\Services\DeviceService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

class DeviceSeeder extends Seeder
{
    public function run(): void
    {
        $svc = new DeviceService;
        $configuredKeys = $this->parseConfiguredKeys();

        $devices = [
            ['code' => 'WS-GRT-001', 'name' => 'Stasiun Garut', 'location_code' => 'GRT', 'status' => DeviceStatus::Active],
            ['code' => 'WS-DPK-001', 'name' => 'Stasiun Depok', 'location_code' => 'DPK', 'status' => DeviceStatus::Active],
            ['code' => 'WS-BGR-001', 'name' => 'Stasiun Bogor', 'location_code' => 'BGR', 'status' => DeviceStatus::Active],
            ['code' => 'WS-SBY-001', 'name' => 'Stasiun Surabaya', 'location_code' => 'SBY', 'status' => DeviceStatus::Provisioned],
        ];

        $now = CarbonImmutable::now('UTC');
        $types = SensorType::orderBy('id')->get();

        foreach ($devices as $d) {
            $loc = Location::where('code', $d['location_code'])->firstOrFail();
            $existing = Device::where('code', $d['code'])->first();
            if ($existing) {
                continue;
            }

            $plainKey = $configuredKeys[$d['code']] ?? null;
            $res = $svc->register([
                'code' => $d['code'],
                'name' => $d['name'],
                'location_id' => $loc->id,
                'create_default_channels' => true,
            ], $plainKey);

            $device = $res['device'];

            if ($d['status'] === DeviceStatus::Active) {
                $device->status = DeviceStatus::Active;
                $device->commissioned_at = $now->subDays(8);
                $device->save();
            }
        }
    }

    private function parseConfiguredKeys(): array
    {
        $raw = (string) config('weather.seed_device_keys', '');
        $keys = [];
        foreach (explode(';', $raw) as $pair) {
            if (str_contains($pair, '=')) {
                [$code, $key] = explode('=', trim($pair), 2);
                $keys[trim($code)] = trim($key);
            }
        }

        return $keys;
    }
}
