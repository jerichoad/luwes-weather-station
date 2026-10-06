<?php

use App\Support\Timescale;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_heartbeats', function (Blueprint $t) {
            $t->timestampTz('time');
            $t->foreignId('device_id')->constrained('devices');
            $t->timestampTz('received_at')->useCurrent();
            $t->float('battery_v')->nullable();
            $t->smallInteger('rssi')->nullable();
            $t->string('firmware_version', 32)->nullable();
            $t->bigInteger('uptime_s')->nullable();

            $t->unique(['device_id', 'time']);
        });

        DB::statement('ALTER TABLE device_heartbeats ALTER COLUMN battery_v TYPE real');
        Timescale::createHypertable('device_heartbeats', '30 days');
    }

    public function down(): void
    {
        Schema::dropIfExists('device_heartbeats');
    }
};
