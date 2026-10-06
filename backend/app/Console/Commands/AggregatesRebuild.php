<?php

namespace App\Console\Commands;

use App\Services\AggregationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class AggregatesRebuild extends Command
{
    protected $signature = 'aggregates:rebuild {--from= : ISO date/datetime} {--to= : ISO date/datetime} {--days= : last N days}';

    protected $description = 'Rebuild semua agregat dalam rentang waktu.';

    public function handle(AggregationService $svc): int
    {
        $to = $this->option('to') ? CarbonImmutable::parse($this->option('to'))->utc() : CarbonImmutable::now('UTC');

        if ($this->option('days')) {
            $from = $to->subDays((int) $this->option('days'));
        } elseif ($this->option('from')) {
            $from = CarbonImmutable::parse($this->option('from'))->utc();
        } else {
            $this->error('Tentukan --from atau --days.');

            return self::FAILURE;
        }

        $this->info("Rebuild aggregates from {$from->toIso8601ZuluString()} to {$to->toIso8601ZuluString()}...");
        $total = $svc->rebuild($from, $to);
        $this->info("Done. Processed {$total} buckets.");

        return self::SUCCESS;
    }
}
