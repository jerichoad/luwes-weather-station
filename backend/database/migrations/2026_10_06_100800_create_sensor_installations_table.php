<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sensor_installations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sensor_id')->constrained('sensors')->restrictOnDelete();
            $t->foreignId('device_channel_id')->constrained('device_channels')->restrictOnDelete();
            $t->timestampTz('installed_at');
            $t->timestampTz('removed_at')->nullable();
            $t->foreignId('installed_by')->nullable()->constrained('users');
            $t->foreignId('removed_by')->nullable()->constrained('users');
            $t->string('removal_reason', 255)->nullable();
            $t->timestampsTz();
        });

        DB::statement('ALTER TABLE sensor_installations ADD CONSTRAINT si_removed_after_installed CHECK (removed_at IS NULL OR removed_at > installed_at)');
        DB::statement("ALTER TABLE sensor_installations ADD CONSTRAINT si_channel_no_overlap EXCLUDE USING gist (device_channel_id WITH =, tstzrange(installed_at, removed_at, '[)') WITH &&)");
        DB::statement("ALTER TABLE sensor_installations ADD CONSTRAINT si_sensor_no_overlap EXCLUDE USING gist (sensor_id WITH =, tstzrange(installed_at, removed_at, '[)') WITH &&)");
        DB::statement('CREATE INDEX sensor_installations_channel_idx ON sensor_installations (device_channel_id, installed_at DESC)');
        DB::statement('CREATE INDEX sensor_installations_sensor_idx ON sensor_installations (sensor_id, installed_at DESC)');
    }

    public function down(): void
    {
        Schema::dropIfExists('sensor_installations');
    }
};
