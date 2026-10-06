<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_status_history', function (Blueprint $t) {
            $t->id();
            $t->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $t->string('from_status', 20)->nullable();
            $t->string('to_status', 20);
            $t->string('reason', 255)->nullable();
            $t->foreignId('changed_by')->nullable()->constrained('users');
            $t->timestampTz('changed_at')->useCurrent();
        });

        DB::statement('CREATE INDEX device_status_history_device_idx ON device_status_history (device_id, changed_at DESC)');
    }

    public function down(): void
    {
        Schema::dropIfExists('device_status_history');
    }
};
