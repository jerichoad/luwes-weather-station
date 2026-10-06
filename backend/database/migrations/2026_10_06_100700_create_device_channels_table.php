<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_channels', function (Blueprint $t) {
            $t->id();
            $t->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $t->foreignId('sensor_type_id')->constrained('sensor_types')->restrictOnDelete();
            $t->string('channel_key', 32);
            $t->string('label', 100)->nullable();
            $t->timestampTz('disabled_at')->nullable();
            $t->timestampsTz();

            $t->unique(['device_id', 'channel_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_channels');
    }
};
