<?php

namespace App\Observers;

use App\Models\TuliproxServer;
use App\Services\TuliproxService;
use Illuminate\Support\Facades\Log;

class TuliproxServerObserver
{
    protected TuliproxService $tuliproxService;

    public function __construct(TuliproxService $tuliproxService)
    {
        $this->tuliproxService = $tuliproxService;
    }

    /**
     * Handle the TuliproxServer "created" event.
     */
    public function created(TuliproxServer $server): void
    {
        Log::info("TuliproxServerObserver: Server {$server->name} created, syncing tuliprox config");
        try {
            $this->tuliproxService->syncApiProxy();
            Log::info("TuliproxServerObserver: Successfully synced config for server {$server->name}");
        } catch (\Exception $e) {
            Log::error("TuliproxServerObserver: Failed to sync config for server {$server->name}: " . $e->getMessage());
            Log::error("TuliproxServerObserver: Stack trace: " . $e->getTraceAsString());
        }
    }

    /**
     * Handle the TuliproxServer "updated" event.
     */
    public function updated(TuliproxServer $server): void
    {
        Log::info("TuliproxServerObserver: Server {$server->name} updated, syncing tuliprox config");
        try {
            $this->tuliproxService->syncApiProxy();
            Log::info("TuliproxServerObserver: Successfully synced config for server {$server->name}");
        } catch (\Exception $e) {
            Log::error("TuliproxServerObserver: Failed to sync config for server {$server->name}: " . $e->getMessage());
            Log::error("TuliproxServerObserver: Stack trace: " . $e->getTraceAsString());
        }
    }

    /**
     * Handle the TuliproxServer "deleted" event.
     */
    public function deleted(TuliproxServer $server): void
    {
        Log::info("TuliproxServerObserver: Server {$server->name} deleted, syncing tuliprox config");
        try {
            $this->tuliproxService->syncApiProxy();
            Log::info("TuliproxServerObserver: Successfully synced config after deleting server {$server->name}");
        } catch (\Exception $e) {
            Log::error("TuliproxServerObserver: Failed to sync config after deleting server {$server->name}: " . $e->getMessage());
            Log::error("TuliproxServerObserver: Stack trace: " . $e->getTraceAsString());
        }
    }

    /**
     * Handle the TuliproxServer "restored" event.
     */
    public function restored(TuliproxServer $server): void
    {
        Log::info("TuliproxServerObserver: Server {$server->name} restored, syncing tuliprox config");
        try {
            $this->tuliproxService->syncApiProxy();
            Log::info("TuliproxServerObserver: Successfully synced config for restored server {$server->name}");
        } catch (\Exception $e) {
            Log::error("TuliproxServerObserver: Failed to sync config for restored server {$server->name}: " . $e->getMessage());
            Log::error("TuliproxServerObserver: Stack trace: " . $e->getTraceAsString());
        }
    }
}
