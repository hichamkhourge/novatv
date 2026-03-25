<?php

namespace App\Console\Commands;

use App\Jobs\RenewUserSubscriptionJob;
use App\Models\IptvUser;
use App\Models\M3uSource;
use Illuminate\Console\Command;

class RunUserRenewalsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'iptv:run-user-renewals {--source= : Only run for specific source ID} {--user= : Only run for specific user ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run automated user subscription renewals for providers (UGEEN, ZAZY, etc.)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting automated user renewals...');

        // Get sources with automation enabled
        $sourcesQuery = M3uSource::where('automation_enabled', true)
            ->where('is_active', true)
            ->whereIn('provider_type', ['ugeen', 'zazy', 'custom']);

        if ($sourceId = $this->option('source')) {
            $sourcesQuery->where('id', $sourceId);
        }

        $sources = $sourcesQuery->get();

        if ($sources->isEmpty()) {
            $this->warn('No sources found with automation enabled.');
            return 0;
        }

        $this->info("Found {$sources->count()} source(s) with automation enabled.");

        $totalUsers = 0;
        $totalDispatched = 0;

        foreach ($sources as $source) {
            $this->line("Processing source: {$source->name} (Provider: {$source->provider_type})");

            // Get active users for this source
            $usersQuery = $source->iptvUsers()
                ->where('is_active', true)
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', now());

            if ($userId = $this->option('user')) {
                $usersQuery->where('id', $userId);
            }

            $users = $usersQuery->get();

            $this->info("  Found {$users->count()} active user(s)");
            $totalUsers += $users->count();

            foreach ($users as $user) {
                try {
                    $this->line("  Dispatching renewal job for user: {$user->username} (ID: {$user->id})");

                    RenewUserSubscriptionJob::dispatch($user, $source);

                    $totalDispatched++;
                } catch (\Exception $e) {
                    $this->error("  Failed to dispatch job for user {$user->username}: {$e->getMessage()}");
                }
            }
        }

        $this->newLine();
        $this->info("✓ Automation complete!");
        $this->table(
            ['Metric', 'Count'],
            [
                ['Sources processed', $sources->count()],
                ['Users found', $totalUsers],
                ['Jobs dispatched', $totalDispatched],
            ]
        );

        return 0;
    }
}
