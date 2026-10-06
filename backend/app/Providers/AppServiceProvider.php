<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        RateLimiter::for('ingest-ip', fn (Request $request) => Limit::perMinute(300)->by('ip:'.$request->ip()));

        RateLimiter::for('ingest-device', function (Request $request) {
            $device = $request->attributes->get('device');

            return Limit::perMinute(30)->by('device:'.($device?->id ?? $request->ip()));
        });
    }
}
