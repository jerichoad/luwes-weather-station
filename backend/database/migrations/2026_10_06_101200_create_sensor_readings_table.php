<?php

use App\Support\Timescale;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sensor_readings', function (Blueprint $t) {
            $t->timestampTz('time');
            $t->foreignId('device_id')->constrained('devices');
            $t->foreignId('channel_id')->constrained('device_channels');
            $t->foreignId('sensor_id')->constrained('sensors');
            $t->double('raw_value');
            $t->double('value')->nullable();
            $t->smallInteger('quality_flags')->default(0);

            // satu-satunya index: dedup + chart + nilai terkini. Wajib memuat kolom time (hypertable).
            $t->unique(['channel_id', 'time']);
        });

        Timescale::createHypertable('sensor_readings', '7 days');
    }

    public function down(): void
    {
        Schema::dropIfExists('sensor_readings');
    }
};
