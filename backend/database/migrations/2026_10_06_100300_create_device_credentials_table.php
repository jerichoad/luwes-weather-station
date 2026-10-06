<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_credentials', function (Blueprint $t) {
            $t->id();
            $t->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $t->string('key_prefix', 12);
            $t->char('key_hash', 64)->unique();
            $t->foreignId('created_by')->nullable()->constrained('users');
            $t->timestampTz('created_at')->useCurrent();
            $t->timestampTz('expires_at')->nullable();
            $t->timestampTz('revoked_at')->nullable();
            $t->timestampTz('last_used_at')->nullable();
        });

        DB::statement('CREATE INDEX device_credentials_active_idx ON device_credentials (device_id) WHERE revoked_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('device_credentials');
    }
};
