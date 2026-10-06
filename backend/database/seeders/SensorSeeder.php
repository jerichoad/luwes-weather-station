<?php

namespace Database\Seeders;

use App\Models\Device;
use App\Models\DeviceChannel;
use App\Models\Sensor;
use App\Models\SensorCalibration;
use App\Models\SensorInstallation;
use App\Models\SensorType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

class SensorSeeder extends Seeder
{
    public function run(): void
    {
        $now = CarbonImmutable::now('UTC');
        $start = $now->subDays(7);
        $t3 = $start->addDays(4); // hari ke-4 (3 hari lalu), momen sensor dipindah

        $typeMap = SensorType::all()->keyBy('code');

        $specs = [
            'temp_air' => [
                'prefix' => 'TA', 'manufacturer' => 'Sensirion', 'model' => 'SHT45',
                'active' => [
                    'WS-GRT-001' => ['sn' => 'TA-0001', 'from' => $start->subDay(), 'to' => null],
                    'WS-DPK-001' => ['sn' => 'TA-0002', 'from' => $start->subDay(), 'to' => $t3, 'reason' => 'Sensor drift, diganti'],
                    'WS-BGR-001' => ['sn' => 'TA-0004', 'from' => $start->subDay(), 'to' => $t3, 'reason' => 'Dipindah ke Depok'],
                ],
                'transfers' => [
                    'WS-DPK-001' => ['sn' => 'TA-0004', 'from' => $t3, 'to' => null],
                    'WS-BGR-001' => ['sn' => 'TA-0005', 'from' => $t3, 'to' => null],
                ],
                'spares' => ['TA-0003'],
            ],
            'humidity' => ['prefix' => 'HU', 'manufacturer' => 'Sensirion', 'model' => 'SHT45', 'spares' => ['HU-0004']],
            'pressure' => ['prefix' => 'PR', 'manufacturer' => 'Bosch', 'model' => 'BMP390'],
            'wind_speed' => ['prefix' => 'WS', 'manufacturer' => 'Davis', 'model' => 'VantagePro2'],
            'wind_dir' => ['prefix' => 'WD', 'manufacturer' => 'Davis', 'model' => 'VantagePro2'],
            'rain_counter' => ['prefix' => 'RC', 'manufacturer' => 'Texas Electronics', 'model' => 'TR-525M'],
            'solar_rad' => ['prefix' => 'SR', 'manufacturer' => 'Apogee', 'model' => 'SP-110'],
        ];

        $grt = Device::where('code', 'WS-GRT-001')->firstOrFail();
        $dpk = Device::where('code', 'WS-DPK-001')->firstOrFail();
        $bgr = Device::where('code', 'WS-BGR-001')->firstOrFail();
        $activeDevs = [$grt, $dpk, $bgr];

        foreach ($specs as $typeCode => $cfg) {
            $type = $typeMap[$typeCode];

            if ($typeCode === 'temp_air') {
                foreach (['TA-0001', 'TA-0002', 'TA-0003', 'TA-0004', 'TA-0005'] as $sn) {
                    $s = Sensor::firstOrCreate(['serial_number' => $sn], [
                        'sensor_type_id' => $type->id,
                        'manufacturer' => $cfg['manufacturer'],
                        'model' => $cfg['model'],
                        'retired_at' => $sn === 'TA-0002' ? $t3 : null,
                    ]);
                    SensorCalibration::firstOrCreate(
                        ['sensor_id' => $s->id, 'effective_from' => $start->subDay()],
                        ['scale_factor' => 1.0, 'offset_value' => 0.0, 'note' => 'Kalibrasi pabrik']
                    );
                }

                $ta4 = Sensor::where('serial_number', 'TA-0004')->firstOrFail();
                SensorCalibration::firstOrCreate(
                    ['sensor_id' => $ta4->id, 'effective_from' => $t3],
                    ['scale_factor' => 1.0, 'offset_value' => -0.3, 'note' => 'Kompensasi suhu Depok']
                );

                foreach ($cfg['active'] as $devCode => $inst) {
                    $d = Device::where('code', $devCode)->firstOrFail();
                    $ch = DeviceChannel::where('device_id', $d->id)->where('channel_key', 'temp_air')->firstOrFail();
                    $s = Sensor::where('serial_number', $inst['sn'])->firstOrFail();
                    SensorInstallation::firstOrCreate(
                        ['sensor_id' => $s->id, 'device_channel_id' => $ch->id, 'installed_at' => $inst['from']],
                        ['removed_at' => $inst['to'], 'removal_reason' => $inst['reason'] ?? null]
                    );
                }

                foreach ($cfg['transfers'] as $devCode => $inst) {
                    $d = Device::where('code', $devCode)->firstOrFail();
                    $ch = DeviceChannel::where('device_id', $d->id)->where('channel_key', 'temp_air')->firstOrFail();
                    $s = Sensor::where('serial_number', $inst['sn'])->firstOrFail();
                    SensorInstallation::firstOrCreate(
                        ['sensor_id' => $s->id, 'device_channel_id' => $ch->id, 'installed_at' => $inst['from']],
                        ['removed_at' => $inst['to']]
                    );
                }

                continue;
            }

            foreach ($activeDevs as $idx => $d) {
                $num = sprintf('%04d', $idx + 1);
                $sn = "{$cfg['prefix']}-{$num}";
                $s = Sensor::firstOrCreate(['serial_number' => $sn], [
                    'sensor_type_id' => $type->id,
                    'manufacturer' => $cfg['manufacturer'],
                    'model' => $cfg['model'],
                ]);

                $offset = ($typeCode === 'pressure' && $d->code === 'WS-GRT-001') ? 1.2 : 0.0;
                SensorCalibration::firstOrCreate(
                    ['sensor_id' => $s->id, 'effective_from' => $start->subDay()],
                    ['scale_factor' => 1.0, 'offset_value' => $offset, 'note' => $offset ? 'Koreksi elevasi Garut' : 'Kalibrasi pabrik']
                );

                $ch = DeviceChannel::where('device_id', $d->id)->where('channel_key', $typeCode)->firstOrFail();
                SensorInstallation::firstOrCreate(
                    ['sensor_id' => $s->id, 'device_channel_id' => $ch->id, 'installed_at' => $start->subDay()],
                    ['removed_at' => null]
                );
            }

            foreach ($cfg['spares'] ?? [] as $sn) {
                Sensor::firstOrCreate(['serial_number' => $sn], [
                    'sensor_type_id' => $type->id,
                    'manufacturer' => $cfg['manufacturer'],
                    'model' => $cfg['model'],
                ]);
            }
        }
    }
}
