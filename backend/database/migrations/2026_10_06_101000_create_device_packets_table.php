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
        Schema::create('device_packets', function (Blueprint $t) {
            $t->timestampTz('time');
            $t->foreignId('device_id')->constrained('devices');
            $t->integer('seq');
            $t->timestampTz('received_at')->useCurrent();
            $t->smallInteger('source');
            $t->float('battery_v')->nullable();
            $t->smallInteger('rssi')->nullable();
            $t->string('firmware_version', 32)->nullable();
            $t->smallInteger('reading_count');
            $t->smallInteger('quality_flags')->default(0);

            $t->unique(['device_id', 'time']);
        });

        DB::statement('ALTER TABLE device_packets ALTER COLUMN battery_v TYPE real');
        Timescale::createHypertable('device_packets', '7 days');
    }

    public function down(): void
    {
        Schema::dropIfExists('device_packets');
    }
};
