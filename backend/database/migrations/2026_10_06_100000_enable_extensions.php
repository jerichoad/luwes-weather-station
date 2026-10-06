<?php

use App\Support\Timescale;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        if (Timescale::availableOnServer()) {
            DB::statement('CREATE EXTENSION IF NOT EXISTS timescaledb');
        }

        Timescale::reset();
    }

    public function down(): void
    {
        DB::statement('DROP EXTENSION IF EXISTS timescaledb');
        DB::statement('DROP EXTENSION IF EXISTS btree_gist');
        Timescale::reset();
    }
};
