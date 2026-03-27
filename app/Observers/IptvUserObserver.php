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
        Log::info("IptvUserObserver: User {$iptvUser->username} created, syncing to tuliprox");
        $this->tuliproxService->addUser($iptvUser);
    }

    /**
     * Handle the IptvUser "updated" event.
     */
    public function updated(IptvUser $iptvUser): void
    {
        Log::info("IptvUserObserver: User {$iptvUser->username} updated, syncing all users to tuliprox");

        // If user was deactivated, remove from tuliprox
        if (!$iptvUser->is_active) {
            $this->tuliproxService->removeUser($iptvUser);
        } else {
            // Otherwise sync all users to ensure consistency
            $this->tuliproxService->syncAllUsers();
        }
    }

    /**
     * Handle the IptvUser "deleted" event.
     */
    public function deleted(IptvUser $iptvUser): void
    {
        Log::info("IptvUserObserver: User {$iptvUser->username} deleted, removing from tuliprox");
        $this->tuliproxService->removeUser($iptvUser);
    }

    /**
     * Handle the IptvUser "restored" event.
     */
    public function restored(IptvUser $iptvUser): void
    {
        Log::info("IptvUserObserver: User {$iptvUser->username} restored, adding to tuliprox");
        $this->tuliproxService->addUser($iptvUser);
    }
}
