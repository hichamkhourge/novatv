<?php

namespace App\Observers;

use App\Models\IptvUser;
use App\Services\TuliproxService;
use Illuminate\Support\Facades\Log;

class IptvUserObserver
{
    protected TuliproxService $tuliproxService;

    public function __construct(TuliproxService $tuliproxService)
    {
        $this->tuliproxService = $tuliproxService;
    }

    /**
     * Handle the IptvUser "created" event.
     */
    public function created(IptvUser $iptvUser): void
    {
        Log::info("IptvUserObserver: User {$iptvUser->username} created, syncing tuliprox config");
        try {
            $this->tuliproxService->syncAll();
            Log::info("IptvUserObserver: Successfully synced config for user {$iptvUser->username}");
        } catch (\Exception $e) {
            Log::error("IptvUserObserver: Failed to sync config for user {$iptvUser->username}: " . $e->getMessage());
            Log::error("IptvUserObserver: Stack trace: " . $e->getTraceAsString());
        }
    }

    /**
     * Handle the IptvUser "updated" event.
     */
    public function updated(IptvUser $iptvUser): void
    {
        Log::info("IptvUserObserver: User {$iptvUser->username} updated, syncing tuliprox config");
        try {
            $this->tuliproxService->syncAll();
            Log::info("IptvUserObserver: Successfully synced config for user {$iptvUser->username}");
        } catch (\Exception $e) {
            Log::error("IptvUserObserver: Failed to sync config for user {$iptvUser->username}: " . $e->getMessage());
            Log::error("IptvUserObserver: Stack trace: " . $e->getTraceAsString());
        }
    }

    /**
     * Handle the IptvUser "deleted" event.
     */
    public function deleted(IptvUser $iptvUser): void
    {
        Log::info("IptvUserObserver: User {$iptvUser->username} deleted, syncing tuliprox config");
        try {
            $this->tuliproxService->syncAll();
            Log::info("IptvUserObserver: Successfully synced config after deleting user {$iptvUser->username}");
        } catch (\Exception $e) {
            Log::error("IptvUserObserver: Failed to sync config after deleting user {$iptvUser->username}: " . $e->getMessage());
            Log::error("IptvUserObserver: Stack trace: " . $e->getTraceAsString());
        }
    }

    /**
     * Handle the IptvUser "restored" event.
     */
    public function restored(IptvUser $iptvUser): void
    {
        Log::info("IptvUserObserver: User {$iptvUser->username} restored, syncing tuliprox config");
        try {
            $this->tuliproxService->syncAll();
            Log::info("IptvUserObserver: Successfully synced config for restored user {$iptvUser->username}");
        } catch (\Exception $e) {
            Log::error("IptvUserObserver: Failed to sync config for restored user {$iptvUser->username}: " . $e->getMessage());
            Log::error("IptvUserObserver: Stack trace: " . $e->getTraceAsString());
        }
    }
}
