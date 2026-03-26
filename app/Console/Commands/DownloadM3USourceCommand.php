<?php

namespace App\Console\Commands;

use App\Models\M3uSource;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class DownloadM3USourceCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'iptv:download-m3u {source_id} {--force : Force re-download even if file exists}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Download a large M3U file from URL and save to storage for file-based parsing';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $sourceId = $this->argument('source_id');
        $force = $this->option('force');

        $source = M3uSource::find($sourceId);

        if (!$source) {
            $this->error("M3U source {$sourceId} not found");
            return 1;
        }

        if ($source->source_type === 'file' && !$force) {
            $this->info("Source is already configured as file-based. Use --force to re-download.");
            $this->info("File path: {$source->file_path}");
            return 0;
        }

        if (!$source->url) {
            $this->error("Source {$sourceId} has no URL configured");
            return 1;
        }

        $this->info("Downloading M3U from: {$source->url}");
        $this->info("This may take several minutes for large files...");

        try {
            // Create filename based on source ID
            $filename = "m3u_sources/source_{$sourceId}.m3u";
            $tempFile = storage_path("app/{$filename}");

            // Ensure directory exists
            if (!is_dir(dirname($tempFile))) {
                mkdir(dirname($tempFile), 0755, true);
            }

            // Download with streaming to handle large files
            // No timeout - let it download completely
            $this->info("Starting download...");
            $startTime = microtime(true);

            $response = Http::withOptions([
                'sink' => $tempFile,  // Stream directly to file
                'timeout' => 0,  // No timeout
                'connect_timeout' => 30,
                'verify' => false,
                'stream' => true,
            ])
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            ])
            ->get($source->url);

            $duration = round(microtime(true) - $startTime, 2);

            if (!$response->successful()) {
                $this->error("Failed to download M3U: HTTP {$response->status()}");
                if (file_exists($tempFile)) {
                    unlink($tempFile);
                }
                return 1;
            }

            // Check file size
            $fileSize = filesize($tempFile);
            $fileSizeMB = round($fileSize / 1024 / 1024, 2);

            $this->info("Download complete!");
            $this->info("File size: {$fileSizeMB} MB");
            $this->info("Download time: {$duration} seconds");

            // Count channels for verification
            $this->info("Counting channels...");
            $channelCount = $this->countChannels($tempFile);
            $this->info("Found {$channelCount} channels in the M3U file");

            // Update source to use file-based parsing
            $source->update([
                'source_type' => 'file',
                'file_path' => $filename,
                'last_fetched_at' => now(),
            ]);

            $this->info("Source {$sourceId} updated to use file-based parsing");
            $this->info("File path: {$filename}");

            Log::info("Downloaded large M3U to storage", [
                'source_id' => $sourceId,
                'file_path' => $filename,
                'file_size_mb' => $fileSizeMB,
                'download_time' => $duration,
                'channel_count' => $channelCount,
            ]);

            return 0;

        } catch (\Exception $e) {
            $this->error("Error downloading M3U: {$e->getMessage()}");
            Log::error("Failed to download M3U source {$sourceId}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return 1;
        }
    }

    /**
     * Count channels in M3U file (streams through file line by line)
     */
    private function countChannels(string $filePath): int
    {
        $count = 0;
        $handle = fopen($filePath, 'r');

        if (!$handle) {
            return 0;
        }

        while (($line = fgets($handle)) !== false) {
            if (str_starts_with(trim($line), '#EXTINF:')) {
                $count++;
            }
        }

        fclose($handle);
        return $count;
    }
}
