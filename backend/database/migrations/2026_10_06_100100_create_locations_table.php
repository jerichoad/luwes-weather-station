<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $t) {
            $t->id();
            $t->string('code', 20)->unique();
            $t->string('name', 150);
            $t->text('address')->nullable();
            $t->decimal('latitude', 9, 6);
            $t->decimal('longitude', 9, 6);
            $t->decimal('altitude_m', 7, 2)->nullable();
            $t->timestampsTz();
            $t->softDeletesTz();
        });

        DB::statement('ALTER TABLE locations ADD CONSTRAINT locations_latitude_check CHECK (latitude BETWEEN -90 AND 90)');
        DB::statement('ALTER TABLE locations ADD CONSTRAINT locations_longitude_check CHECK (longitude BETWEEN -180 AND 180)');
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
