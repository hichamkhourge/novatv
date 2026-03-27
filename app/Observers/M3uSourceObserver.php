<?php

namespace App\Observers;

use App\Models\M3uSource;
use App\Services\TuliproxService;
use Illuminate\Support\Facades\Log;

class M3uSourceObserver
{
    protected TuliproxService $tuliproxService;

    public function __construct(TuliproxService $tuliproxService)
    {
        $this->tuliproxService = $tuliproxService;
    }

    /**
     * Handle the M3uSource "created" event.
     */
    public function created(M3uSource $m3uSource): void
    {
        Log::info("M3uSourceObserver: Source {$m3uSource->name} created, syncing tuliprox config");
        $this->tuliproxService->syncAll();
    }

    /**
     * Handle the M3uSource "updated" event.
     */
    public function updated(M3uSource $m3uSource): void
    {
        Log::info("M3uSourceObserver: Source {$m3uSource->name} updated, syncing tuliprox config");
        $this->tuliproxService->syncAll();
    }

    /**
     * Handle the M3uSource "deleted" event.
     */
    public function deleted(M3uSource $m3uSource): void
    {
        Log::info("M3uSourceObserver: Source {$m3uSource->name} deleted, syncing tuliprox config");
        $this->tuliproxService->syncAll();
    }

    /**
     * Handle the M3uSource "restored" event.
     */
    public function restored(M3uSource $m3uSource): void
    {
        Log::info("M3uSourceObserver: Source {$m3uSource->name} restored, syncing tuliprox config");
        $this->tuliproxService->syncAll();
    }
}
