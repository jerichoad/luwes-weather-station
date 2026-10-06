<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sensor_calibrations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sensor_id')->constrained('sensors')->cascadeOnDelete();
            $t->double('scale_factor')->default(1);
            $t->double('offset_value')->default(0);
            $t->timestampTz('effective_from');
            $t->string('note', 255)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users');
            $t->timestampTz('created_at')->useCurrent();

            $t->unique(['sensor_id', 'effective_from']);
        });

        DB::statement('ALTER TABLE sensor_calibrations ADD CONSTRAINT sensor_calibrations_scale_check CHECK (scale_factor <> 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('sensor_calibrations');
    }
};
