<?php

namespace App\Console\Commands;

use App\Jobs\SyncM3uSourceJob;
use App\Models\M3uSource;
use Illuminate\Console\Command;

class SyncM3uCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'm3u:sync {source_id? : The ID of the M3U source to sync (optional)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync M3U sources - download, parse, and update channels in database';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $sourceId = $this->argument('source_id');

        if ($sourceId) {
            return $this->syncSingleSource((int) $sourceId);
        }

        return $this->syncAllSources();
    }

    /**
     * Sync a single M3U source
     */
    private function syncSingleSource(int $sourceId): int
    {
        $source = M3uSource::find($sourceId);

        if (!$source) {
            $this->error("M3U source with ID {$sourceId} not found.");
            return self::FAILURE;
        }

        if ($source->status === 'syncing') {
            $this->warn("M3U source \"{$source->name}\" is already syncing. Skipping.");
            return self::SUCCESS;
        }

        if (!$source->is_active) {
            $this->warn("M3U source \"{$source->name}\" is inactive. Skipping.");
            return self::SUCCESS;
        }

        $this->info("Dispatching sync job for M3U source: {$source->name} (ID: {$source->id})");

        SyncM3uSourceJob::dispatch($source->id);

        $this->info("Sync job dispatched. Check queue logs for progress.");

        return self::SUCCESS;
    }

    /**
     * Sync all active M3U sources that aren't currently syncing
     */
    private function syncAllSources(): int
    {
        $sources = M3uSource::active()
            ->where('status', '!=', 'syncing')
            ->get();

        if ($sources->isEmpty()) {
            $this->info("No M3U sources available for syncing.");
            return self::SUCCESS;
        }

        $this->info("Found {$sources->count()} M3U source(s) to sync.");

        foreach ($sources as $source) {
            $this->line("  → Dispatching sync for: {$source->name} (ID: {$source->id})");
            SyncM3uSourceJob::dispatch($source->id);
        }

        $this->info("All sync jobs dispatched. Check queue logs for progress.");

        return self::SUCCESS;
    }
}
