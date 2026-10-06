<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sensor_types', function (Blueprint $t) {
            $t->id();
            $t->string('code', 32)->unique();
            $t->string('name', 100);
            $t->string('unit', 16);
            $t->string('kind', 10);
            $t->double('min_value');
            $t->double('max_value');
            $t->smallInteger('precision')->default(1);
            $t->double('counter_factor')->nullable();
            $t->string('derived_unit', 16)->nullable();
            $t->jsonb('error_codes')->default('[]');
            $t->string('default_agg', 8)->default('avg');
            $t->timestampsTz();
        });

        DB::statement("ALTER TABLE sensor_types ADD CONSTRAINT sensor_types_kind_check CHECK (kind IN ('gauge','counter','angle'))");
        DB::statement('ALTER TABLE sensor_types ADD CONSTRAINT sensor_types_range_check CHECK (min_value < max_value)');
    }

    public function down(): void
    {
        Schema::dropIfExists('sensor_types');
    }
};
