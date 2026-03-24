<?php

namespace App\Console\Commands;

use App\Services\ConnectionTrackerService;
use Illuminate\Console\Command;

class CleanupSessions extends Command
{
    protected $signature = 'iptv:cleanup-sessions';
    protected $description = 'Delete stale stream sessions';

    public function handle(ConnectionTrackerService $connectionTracker)
    {
        $deleted = $connectionTracker->cleanupStale();

        $this->info("Cleaned up {$deleted} stale session(s)");

        return 0;
    }
}
