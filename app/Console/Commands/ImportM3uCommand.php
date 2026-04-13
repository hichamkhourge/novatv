<?php

namespace App\Console\Commands;

use App\Jobs\ImportM3uJob;
use Illuminate\Console\Command;

class ImportM3uCommand extends Command
{
    protected $signature   = 'iptv:import-m3u {url : URL or local file path to an M3U playlist}';
    protected $description = 'Import channels and channel groups from an M3U playlist';

    public function handle(): int
    {
        $source = $this->argument('url');

        $this->info("Importing M3U from: {$source}");
        $this->newLine();

        $job = new ImportM3uJob($source);
        $summary = $job->handle();

        $this->table(
            ['Created', 'Updated', 'Skipped'],
            [[$summary['created'], $summary['updated'], $summary['skipped']]],
        );

        $this->newLine();
        $this->info('Import complete.');

        return self::SUCCESS;
    }
}
