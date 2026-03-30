<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('web')
                ->group(base_path('routes/xtream.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Trust all proxies (Traefik) for proper HTTPS detection
        $middleware->trustProxies(at: '*', headers: Illuminate\Http\Request::HEADER_X_FORWARDED_FOR | Illuminate\Http\Request::HEADER_X_FORWARDED_HOST | Illuminate\Http\Request::HEADER_X_FORWARDED_PORT | Illuminate\Http\Request::HEADER_X_FORWARDED_PROTO);
    })
    ->withSchedule(function (Schedule $schedule) {
        // Sync M3U sources daily at 3 AM
        $schedule->command('m3u:sync')->dailyAt('03:00');

        // Cleanup old M3U temp files hourly
        $schedule->command('m3u:clean-temp')->hourly();
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
