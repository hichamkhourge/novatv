<?php

namespace App\Console\Commands;

use App\Models\IptvUser;
use App\Models\M3uSource;
use App\Services\TuliproxService;
use Illuminate\Console\Command;

class TuliproxSync extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tuliprox:sync
                            {--users : Sync only users}
                            {--sources : Sync only sources}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync active IPTV users and M3U sources to tuliprox configuration files';

    protected TuliproxService $tuliproxService;

    /**
     * Execute the console command.
     */
    public function handle(TuliproxService $tuliproxService): int
    {
        $this->tuliproxService = $tuliproxService;

        $syncUsers = !$this->option('sources');
        $syncSources = !$this->option('users');

        // If both options are provided or none, sync both
        if ($this->option('users') && $this->option('sources')) {
            $syncUsers = true;
            $syncSources = true;
        }

        $this->info('Starting tuliprox synchronization...');
        $this->newLine();

        $success = true;

        // Sync users
        if ($syncUsers) {
            $success = $this->syncUsers() && $success;
        }

        // Sync sources
        if ($syncSources) {
            $success = $this->syncSources() && $success;
        }

        $this->newLine();

        if ($success) {
            $this->info('Tuliprox synchronization completed successfully!');
            return self::SUCCESS;
        } else {
            $this->error('Tuliprox synchronization completed with errors. Check logs for details.');
            return self::FAILURE;
        }
    }

    /**
     * Sync users to tuliprox
     */
    protected function syncUsers(): bool
    {
        $this->line('Syncing IPTV users...');

        try {
            $activeUsers = IptvUser::where('is_active', true)->get();
            $totalUsers = $activeUsers->count();

            if ($totalUsers === 0) {
                $this->warn('  No active users found to sync.');
                return true;
            }

            $result = $this->tuliproxService->syncAllUsers();

            if ($result) {
                $this->info("  ✓ Successfully synced {$totalUsers} active user(s) to user.yml");
                return true;
            } else {
                $this->error('  ✗ Failed to sync users');
                return false;
            }
        } catch (\Exception $e) {
            $this->error('  ✗ Error syncing users: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Sync sources to tuliprox
     */
    protected function syncSources(): bool
    {
        $this->line('Syncing M3U sources...');

        try {
            $activeSources = M3uSource::where('is_active', true)->get();
            $totalSources = $activeSources->count();

            if ($totalSources === 0) {
                $this->warn('  No active sources found to sync.');
                return true;
            }

            $result = $this->tuliproxService->syncSources();

            if ($result) {
                $this->info("  ✓ Successfully synced {$totalSources} active source(s) to source.yml");
                return true;
            } else {
                $this->error('  ✗ Failed to sync sources');
                return false;
            }
        } catch (\Exception $e) {
            $this->error('  ✗ Error syncing sources: ' . $e->getMessage());
            return false;
        }
    }
}
