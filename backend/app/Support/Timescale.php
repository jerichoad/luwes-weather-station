<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

final class Timescale
{
    private static ?bool $enabled = null;

    public static function availableOnServer(): bool
    {
        return (bool) DB::scalar("SELECT EXISTS (SELECT 1 FROM pg_available_extensions WHERE name = 'timescaledb')");
    }

    public static function enabled(): bool
    {
        return self::$enabled ??= (bool) DB::scalar("SELECT EXISTS (SELECT 1 FROM pg_extension WHERE extname = 'timescaledb')");
    }

    public static function reset(): void
    {
        self::$enabled = null;
    }

    public static function createHypertable(string $table, string $chunkInterval): void
    {
        self::reset();
        if (! self::enabled()) {
            return;
        }

        DB::statement("SELECT create_hypertable('{$table}', by_range('time', INTERVAL '{$chunkInterval}'), create_default_indexes => false)");
    }
}
