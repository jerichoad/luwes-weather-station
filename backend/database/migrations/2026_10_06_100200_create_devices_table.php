<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $t) {
            $t->id();
            $t->string('code', 32)->unique();
            $t->string('name', 150);
            $t->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $t->string('status', 20)->default('provisioned');
            $t->string('firmware_version', 32)->nullable();
            $t->timestampTz('last_seen_at')->nullable();
            $t->timestampTz('last_telemetry_received_at')->nullable();
            $t->timestampTz('last_reading_at')->nullable();
            $t->float('last_battery_v')->nullable();
            $t->smallInteger('last_rssi')->nullable();
            $t->bigInteger('last_uptime_s')->nullable();
            $t->timestampTz('last_boot_at')->nullable();
            $t->integer('clock_offset_s')->nullable();
            $t->timestampTz('commissioned_at')->nullable();
            $t->timestampTz('decommissioned_at')->nullable();
            $t->timestampsTz();
            $t->softDeletesTz();

            $t->index('status', 'devices_status_idx');
            $t->index('location_id', 'devices_location_idx');
            $t->index('last_seen_at', 'devices_last_seen_idx');
        });

        DB::statement("ALTER TABLE devices ADD CONSTRAINT devices_status_check CHECK (status IN ('provisioned','active','maintenance','decommissioned'))");
        DB::statement('ALTER TABLE devices ALTER COLUMN last_battery_v TYPE real');
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
