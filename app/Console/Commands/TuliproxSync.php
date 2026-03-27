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
                            {--users : Sync only user.yml}
                            {--sources : Sync only source.yml}
                            {--api-proxy : Sync only api-proxy.yml}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync all Tuliprox configuration files (source.yml, user.yml, api-proxy.yml)';

    protected TuliproxService $tuliproxService;

    /**
     * Execute the console command.
     */
    public function handle(TuliproxService $tuliproxService): int
    {
        $this->tuliproxService = $tuliproxService;

        // Check if specific files are requested
        $syncUsers = $this->option('users');
        $syncSources = $this->option('sources');
        $syncApiProxy = $this->option('api-proxy');

        // If no options provided, sync all
        $syncAll = !$syncUsers && !$syncSources && !$syncApiProxy;

        $this->info('Starting tuliprox synchronization...');
        $this->newLine();

        $success = true;

        if ($syncAll) {
            // Sync all files
            $success = $this->syncAll();
        } else {
            // Sync specific files
            if ($syncSources) {
                $success = $this->syncSources() && $success;
            }

            if ($syncUsers) {
                $success = $this->syncUsers() && $success;
            }

            if ($syncApiProxy) {
                $success = $this->syncApiProxy() && $success;
            }
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
     * Sync all configuration files
     */
    protected function syncAll(): bool
    {
        $this->line('Syncing all configuration files...');
        $this->newLine();

        $success = true;
        $success = $this->syncSources() && $success;
        $success = $this->syncUsers() && $success;
        $success = $this->syncApiProxy() && $success;

        return $success;
    }

    /**
     * Sync user.yml
     */
    protected function syncUsers(): bool
    {
        $this->line('Syncing user.yml...');

        try {
            $activeUsers = IptvUser::where('is_active', true)->get();
            $totalUsers = $activeUsers->count();

            if ($totalUsers === 0) {
                $this->warn('  No active users found to sync.');
            }

            $result = $this->tuliproxService->syncUsers();

            if ($result) {
                $this->info("  ✓ Successfully synced {$totalUsers} active user(s) to user.yml");
                return true;
            } else {
                $this->error('  ✗ Failed to sync user.yml');
                return false;
            }
        } catch (\Exception $e) {
            $this->error('  ✗ Error syncing user.yml: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Sync source.yml
     */
    protected function syncSources(): bool
    {
        $this->line('Syncing source.yml...');

        try {
            $activeSources = M3uSource::where('is_active', true)->get();
            $totalSources = $activeSources->count();

            if ($totalSources === 0) {
                $this->warn('  No active sources found to sync.');
            }

            $result = $this->tuliproxService->syncSources();

            if ($result) {
                $this->info("  ✓ Successfully synced {$totalSources} active source(s) to source.yml");
                return true;
            } else {
                $this->error('  ✗ Failed to sync source.yml');
                return false;
            }
        } catch (\Exception $e) {
            $this->error('  ✗ Error syncing source.yml: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Sync api-proxy.yml
     */
    protected function syncApiProxy(): bool
    {
        $this->line('Syncing api-proxy.yml...');

        try {
            $result = $this->tuliproxService->syncApiProxy();

            if ($result) {
                $this->info("  ✓ Successfully synced api-proxy.yml");
                return true;
            } else {
                $this->error('  ✗ Failed to sync api-proxy.yml');
                return false;
            }
        } catch (\Exception $e) {
            $this->error('  ✗ Error syncing api-proxy.yml: ' . $e->getMessage());
            return false;
        }
    }
}
