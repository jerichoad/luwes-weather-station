<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aggregate_refresh_queue', function (Blueprint $t) {
            $t->unsignedBigInteger('channel_id');
            $t->timestampTz('bucket_start');
            $t->timestampTz('enqueued_at')->useCurrent();

            $t->primary(['channel_id', 'bucket_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aggregate_refresh_queue');
    }
};
