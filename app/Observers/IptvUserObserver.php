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
        $this->tuliproxService->syncAll();
    }

    /**
     * Handle the IptvUser "updated" event.
     */
    public function updated(IptvUser $iptvUser): void
    {
        Log::info("IptvUserObserver: User {$iptvUser->username} updated, syncing tuliprox config");
        $this->tuliproxService->syncAll();
    }

    /**
     * Handle the IptvUser "deleted" event.
     */
    public function deleted(IptvUser $iptvUser): void
    {
        Log::info("IptvUserObserver: User {$iptvUser->username} deleted, syncing tuliprox config");
        $this->tuliproxService->syncAll();
    }

    /**
     * Handle the IptvUser "restored" event.
     */
    public function restored(IptvUser $iptvUser): void
    {
        Log::info("IptvUserObserver: User {$iptvUser->username} restored, syncing tuliprox config");
        $this->tuliproxService->syncAll();
    }
}
