<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * @deprecated Replaced by iptv:import-m3u. This command does nothing.
 */
class SyncM3uCommand extends Command
{
    protected $signature   = 'm3u:sync {source_id?}';
    protected $description = '[Deprecated] Use iptv:import-m3u instead.';

    public function handle(): int
    {
        $this->warn('This command is deprecated. Use: php artisan iptv:import-m3u {url}');
        return self::SUCCESS;
    }
}
