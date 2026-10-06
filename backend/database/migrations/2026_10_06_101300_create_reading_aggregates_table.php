<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reading_aggregates', function (Blueprint $t) {
            $t->foreignId('channel_id')->constrained('device_channels');
            $t->string('bucket_interval', 3);
            $t->timestampTz('bucket_start');
            $t->unsignedBigInteger('device_id');
            $t->unsignedBigInteger('sensor_type_id');
            $t->integer('sample_count');
            $t->integer('good_count');
            $t->double('avg_value')->nullable();
            $t->double('min_value')->nullable();
            $t->double('max_value')->nullable();
            $t->double('sum_value')->nullable();
            $t->double('sum_x')->nullable();
            $t->double('sum_y')->nullable();
            $t->timestampTz('computed_at')->useCurrent();

            $t->primary(['channel_id', 'bucket_interval', 'bucket_start']);
        });

        DB::statement("ALTER TABLE reading_aggregates ADD CONSTRAINT reading_aggregates_interval_check CHECK (bucket_interval IN ('1h','1d'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('reading_aggregates');
    }
};
