<?php

namespace Database\Seeders;

use App\Models\Location;
use Illuminate\Database\Seeder;

class LocationSeeder extends Seeder
{
    public function run(): void
    {
        $locations = [
            ['code' => 'GRT', 'name' => 'Garut', 'address' => 'Kabupaten Garut, Jawa Barat', 'latitude' => -7.227900, 'longitude' => 107.908700, 'altitude_m' => 717.0],
            ['code' => 'DPK', 'name' => 'Depok', 'address' => 'Kota Depok, Jawa Barat', 'latitude' => -6.402500, 'longitude' => 106.794200, 'altitude_m' => 100.0],
            ['code' => 'BGR', 'name' => 'Bogor', 'address' => 'Kota Bogor, Jawa Barat', 'latitude' => -6.597100, 'longitude' => 106.806000, 'altitude_m' => 265.0],
            ['code' => 'SBY', 'name' => 'Surabaya', 'address' => 'Kota Surabaya, Jawa Timur', 'latitude' => -7.257500, 'longitude' => 112.752100, 'altitude_m' => 5.0],
        ];

        foreach ($locations as $l) {
            Location::updateOrCreate(['code' => $l['code']], $l);
        }
    }
}
