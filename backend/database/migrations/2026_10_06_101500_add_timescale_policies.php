<?php

use App\Support\Timescale;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var array<string, array{segmentby:string, compress:string, retain:string}> */
    private array $tables = [
        'sensor_readings' => ['segmentby' => 'channel_id', 'compress' => '7 days', 'retain' => '2 years'],
        'device_packets' => ['segmentby' => 'device_id', 'compress' => '7 days', 'retain' => '2 years'],
        'device_heartbeats' => ['segmentby' => 'device_id', 'compress' => '30 days', 'retain' => '1 year'],
    ];

    public function up(): void
    {
        Timescale::reset();
        if (! Timescale::enabled()) {
            return;
        }

        foreach ($this->tables as $table => $p) {
            DB::statement("ALTER TABLE {$table} SET (timescaledb.compress, timescaledb.compress_segmentby = '{$p['segmentby']}', timescaledb.compress_orderby = 'time DESC')");
            DB::statement("SELECT add_compression_policy('{$table}', INTERVAL '{$p['compress']}')");
            DB::statement("SELECT add_retention_policy('{$table}', INTERVAL '{$p['retain']}')");
        }
    }

    public function down(): void
    {
        Timescale::reset();
        if (! Timescale::enabled()) {
            return;
        }

        foreach (array_keys($this->tables) as $table) {
            DB::statement("SELECT remove_retention_policy('{$table}', if_exists => true)");
            DB::statement("SELECT remove_compression_policy('{$table}', if_exists => true)");
            DB::statement("SELECT decompress_chunk(c, true) FROM show_chunks('{$table}') c");
            DB::statement("ALTER TABLE {$table} SET (timescaledb.compress = false)");
        }
    }
};
