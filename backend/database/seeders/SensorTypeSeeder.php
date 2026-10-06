<?php

namespace Database\Seeders;

use App\Models\SensorType;
use Illuminate\Database\Seeder;

class SensorTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['code' => 'temp_air', 'name' => 'Suhu Udara', 'unit' => '°C', 'kind' => 'gauge', 'min_value' => -40.0, 'max_value' => 60.0, 'precision' => 1, 'error_codes' => [-999], 'default_agg' => 'avg'],
            ['code' => 'humidity', 'name' => 'Kelembapan Relatif', 'unit' => '%', 'kind' => 'gauge', 'min_value' => 0.0, 'max_value' => 100.0, 'precision' => 1, 'error_codes' => [-999], 'default_agg' => 'avg'],
            ['code' => 'pressure', 'name' => 'Tekanan Udara', 'unit' => 'hPa', 'kind' => 'gauge', 'min_value' => 800.0, 'max_value' => 1100.0, 'precision' => 1, 'error_codes' => [-999], 'default_agg' => 'avg'],
            ['code' => 'wind_speed', 'name' => 'Kecepatan Angin', 'unit' => 'm/s', 'kind' => 'gauge', 'min_value' => 0.0, 'max_value' => 75.0, 'precision' => 1, 'error_codes' => [], 'default_agg' => 'avg'],
            ['code' => 'wind_dir', 'name' => 'Arah Angin', 'unit' => '°', 'kind' => 'angle', 'min_value' => 0.0, 'max_value' => 359.0, 'precision' => 0, 'error_codes' => [], 'default_agg' => 'avg'],
            ['code' => 'rain_counter', 'name' => 'Curah Hujan (Tipping Bucket)', 'unit' => 'tip', 'kind' => 'counter', 'min_value' => 0.0, 'max_value' => 1000000.0, 'precision' => 0, 'counter_factor' => 0.2, 'derived_unit' => 'mm', 'error_codes' => [], 'default_agg' => 'sum'],
            ['code' => 'solar_rad', 'name' => 'Radiasi Matahari', 'unit' => 'W/m²', 'kind' => 'gauge', 'min_value' => 0.0, 'max_value' => 1500.0, 'precision' => 1, 'error_codes' => [], 'default_agg' => 'avg'],
        ];

        foreach ($types as $t) {
            SensorType::updateOrCreate(['code' => $t['code']], $t);
        }
    }
}
