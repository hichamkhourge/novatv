<?php

use App\Jobs\ImportM3uJob;
use App\Jobs\ImportXtreamJob;
use App\Models\IptvAccount;
use App\Models\M3uSource;
use App\Models\StreamSession;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Log;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // IPTV client-facing routes — no session, no CSRF, no cookies
            Route::middleware([])
                ->group(base_path('routes/iptv.php'));

            // Webhook routes — no session, no CSRF
            Route::prefix('api/webhooks')
                ->middleware(['api'])
                ->group(function () {
                    Route::post('/zazy-automation', [\App\Http\Controllers\ZazyWebhookController::class, 'handleCallback']);
                });
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

        // Exclude IPTV endpoints and webhooks from CSRF verification
        $middleware->validateCsrfTokens(except: [
            '/get.php',
            '/player_api.php',
            '/panel_api.php',
            '/live/*',
            '/api/webhooks/*',
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

        // Renew provider-managed accounts (Zazy, Ugeen, etc.) daily at 03:00
        $schedule->command('providers:renew')
            ->dailyAt('03:00')
            ->name('renew-provider-accounts')
            ->withoutOverlapping()
            ->runInBackground();

        // Periodic source refresh prevents stale stream_id mappings when
        // providers rotate IDs (common with Xtream/live sources).
        $schedule->call(function () {
            if (! config('iptv.sources.auto_sync_enabled', true)) {
                return;
            }

            $staleMinutes = max((int) config('iptv.sources.auto_sync_stale_minutes', 180), 5);
            $batchSize = max((int) config('iptv.sources.auto_sync_batch_size', 2), 1);
            $staleBefore = now()->subMinutes($staleMinutes);

            $sources = M3uSource::query()
                ->where('is_active', true)
                ->where('status', '!=', 'syncing')
                ->where(function ($query) use ($staleBefore) {
                    $query->whereNull('last_synced_at')
                        ->orWhere('last_synced_at', '<=', $staleBefore);
                })
                ->orderBy('last_synced_at')
                ->limit($batchSize)
                ->get();

            foreach ($sources as $source) {
                try {
                    if ($source->isXtream()) {
                        if (! $source->xtream_host || ! $source->xtream_username || ! $source->xtream_password) {
                            Log::warning('auto-sync skipped xtream source with missing credentials', [
                                'source_id' => $source->id,
                                'name' => $source->name,
                            ]);
                            continue;
                        }

                        $source->update(['status' => 'syncing', 'error_message' => null]);
                        ImportXtreamJob::dispatch($source->id);
                        continue;
                    }

                    $importSource = $source->isFileSource()
                        ? $source->getFullFilePath()
                        : $source->url;

                    if (! $importSource) {
                        Log::warning('auto-sync skipped source with no URL/file', [
                            'source_id' => $source->id,
                            'name' => $source->name,
                            'source_type' => $source->source_type,
                        ]);
                        continue;
                    }

                    $source->update(['status' => 'syncing', 'error_message' => null]);
                    ImportM3uJob::dispatch($importSource, $source->id);
                } catch (\Throwable $e) {
                    Log::error('auto-sync dispatch failed', [
                        'source_id' => $source->id,
                        'name' => $source->name,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        })->everyTenMinutes()->name('auto-sync-active-sources')->withoutOverlapping();
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
