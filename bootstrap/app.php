<?php

use App\Models\IptvAccount;
use App\Models\StreamSession;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // IPTV client-facing routes — no session, no CSRF, no cookies
            Route::middleware([])
                ->group(base_path('routes/iptv.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Trust all proxies (Traefik) for proper HTTPS detection
        $middleware->trustProxies(
            at: '*',
            headers: Illuminate\Http\Request::HEADER_X_FORWARDED_FOR
                | Illuminate\Http\Request::HEADER_X_FORWARDED_HOST
                | Illuminate\Http\Request::HEADER_X_FORWARDED_PORT
                | Illuminate\Http\Request::HEADER_X_FORWARDED_PROTO,
        );

        // Exclude IPTV endpoints from CSRF verification
        $middleware->validateCsrfTokens(except: [
            '/get.php',
            '/player_api.php',
            '/live/*',
        ]);
    })
    ->withSchedule(function (Schedule $schedule) {
        // Purge stale stream sessions every minute (last_seen_at older than 60s)
        $schedule->call(function () {
            StreamSession::where('last_seen_at', '<', now()->subSeconds(60))->delete();
        })->everyMinute()->name('purge-stale-sessions')->withoutOverlapping();

        // Mark expired accounts daily
        $schedule->call(function () {
            IptvAccount::where('status', 'active')
                ->whereNotNull('expires_at')
                ->where('expires_at', '<', now())
                ->update(['status' => 'expired']);
        })->daily()->name('expire-iptv-accounts')->withoutOverlapping();
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
