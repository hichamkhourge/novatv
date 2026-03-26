<?php

namespace App\Console\Commands;

use App\Services\HlsTranscoderService;
use Illuminate\Console\Command;

class CleanupHlsStreamsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'iptv:cleanup-hls';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Cleanup old HLS transcoding processes and segments';

    /**
     * Execute the console command.
     */
    public function handle(HlsTranscoderService $transcoder): int
    {
        $this->info('Cleaning up old HLS streams...');

        try {
            $cleaned = $transcoder->cleanupOldStreams();

            $this->info("Successfully cleaned up {$cleaned} old stream(s).");

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Cleanup failed: ' . $e->getMessage());

            return Command::FAILURE;
        }
    }
}
