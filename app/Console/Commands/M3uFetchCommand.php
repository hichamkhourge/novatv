<?php

namespace App\Console\Commands;

use App\Models\M3uSource;
use App\Services\M3uFetcherService;
use Illuminate\Console\Command;

class M3uFetchCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'm3u:fetch
                            {source? : The ID of the M3U source to fetch}
                            {--all : Fetch all active sources}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch and sync channels from M3U sources';

    protected M3uFetcherService $fetcher;

    public function __construct(M3uFetcherService $fetcher)
    {
        parent::__construct();
        $this->fetcher = $fetcher;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if ($this->option('all')) {
            return $this->fetchAll();
        }

        $sourceId = $this->argument('source');

        if (!$sourceId) {
            $this->error('Please provide a source ID or use --all flag');
            return 1;
        }

        return $this->fetchSingle($sourceId);
    }

    /**
     * Fetch a single source
     */
    protected function fetchSingle(int $sourceId): int
    {
        $source = M3uSource::find($sourceId);

        if (!$source) {
            $this->error("Source with ID {$sourceId} not found");
            return 1;
        }

        $this->info("Fetching channels from: {$source->name}");
        $this->line("URL: " . ($source->url ?? $source->file_path));
        $this->newLine();

        try {
            $stats = $this->fetcher->fetchAndSync($source);

            $this->info("✓ Fetch completed successfully!");
            $this->newLine();
            $this->table(
                ['Metric', 'Count'],
                [
                    ['Total Channels', $stats['total']],
                    ['Added', $stats['added']],
                    ['Updated', $stats['updated']],
                    ['Removed', $stats['removed']],
                ]
            );

            // Show group breakdown
            $groups = $this->fetcher->getGroupNames($source);
            if (!empty($groups)) {
                $this->newLine();
                $this->info("Channel Groups (" . count($groups) . "):");
                foreach ($groups as $group) {
                    $this->line("  - {$group}");
                }
            }

            return 0;
        } catch (\Exception $e) {
            $this->error("✗ Failed to fetch channels: " . $e->getMessage());
            return 1;
        }
    }

    /**
     * Fetch all active sources
     */
    protected function fetchAll(): int
    {
        $sources = M3uSource::where('is_active', true)->get();

        if ($sources->isEmpty()) {
            $this->warn('No active M3U sources found');
            return 0;
        }

        $this->info("Fetching channels from {$sources->count()} active source(s)...");
        $this->newLine();

        $successful = 0;
        $failed = 0;

        foreach ($sources as $source) {
            $this->line("Processing: {$source->name}");

            try {
                $stats = $this->fetcher->fetchAndSync($source);

                $this->info("  ✓ {$stats['total']} channels ({$stats['added']} added, {$stats['updated']} updated, {$stats['removed']} removed)");
                $successful++;
            } catch (\Exception $e) {
                $this->error("  ✗ Failed: " . $e->getMessage());
                $failed++;
            }
        }

        $this->newLine();
        $this->info("Completed: {$successful} successful, {$failed} failed");

        return $failed > 0 ? 1 : 0;
    }
}
