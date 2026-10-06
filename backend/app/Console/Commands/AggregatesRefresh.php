<?php

namespace App\Console\Commands;

use App\Services\AggregationService;
use Illuminate\Console\Command;

class AggregatesRefresh extends Command
{
    protected $signature = 'aggregates:refresh {--limit=500}';

    protected $description = 'Ambil antrian agregat dan hitung ulang.';

    public function handle(AggregationService $svc): int
    {
        $total = $svc->refreshAll((int) $this->option('limit'));
        $this->info("Processed {$total} buckets.");

        return self::SUCCESS;
    }
}
