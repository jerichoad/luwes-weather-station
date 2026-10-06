<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sensors', function (Blueprint $t) {
            $t->id();
            $t->string('serial_number', 64)->unique();
            $t->foreignId('sensor_type_id')->constrained('sensor_types')->restrictOnDelete();
            $t->string('manufacturer', 100)->nullable();
            $t->string('model', 100)->nullable();
            $t->timestampTz('retired_at')->nullable();
            $t->text('notes')->nullable();
            $t->timestampsTz();
            $t->softDeletesTz();

            $t->index('sensor_type_id', 'sensors_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sensors');
    }
};
