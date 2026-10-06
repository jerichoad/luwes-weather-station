<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            SensorTypeSeeder::class,
            LocationSeeder::class,
            DeviceSeeder::class,
            SensorSeeder::class,
            HistoricalDataSeeder::class,
        ]);

        $this->command?->info('Rebuilding aggregates for the seeded 7 days...');
        Artisan::call('aggregates:rebuild', ['--days' => 8]);
        $this->command?->info('Seeding completed successfully.');
    }
}
