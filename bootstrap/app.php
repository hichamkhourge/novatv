<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Console\Scheduling\Schedule;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Trust all proxies (Traefik) for proper HTTPS detection
        $middleware->trustProxies(at: '*', headers: Illuminate\Http\Request::HEADER_X_FORWARDED_FOR | Illuminate\Http\Request::HEADER_X_FORWARDED_HOST | Illuminate\Http\Request::HEADER_X_FORWARDED_PORT | Illuminate\Http\Request::HEADER_X_FORWARDED_PROTO);
    })
    ->withSchedule(function (Schedule $schedule) {
        // Refresh M3U channels every hour
        $schedule->command('iptv:refresh-m3u')->hourly();

        // Cleanup stale sessions every minute
        $schedule->command('iptv:cleanup-sessions')->everyMinute();

        // Cleanup old HLS transcoding processes every 5 minutes
        $schedule->command('iptv:cleanup-hls')->everyFiveMinutes();

        // Run user subscription renewals daily at 2 AM
        $schedule->command('iptv:run-user-renewals')->dailyAt('02:00');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
