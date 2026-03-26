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
            // Using native PHP file operations for better control
            $this->info("Starting download...");
            $startTime = microtime(true);

            // Use PHP's native stream functions for large file downloads
            $ctx = stream_context_create([
                'http' => [
                    'timeout' => 0,  // No timeout
                    'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                    'follow_location' => true,
                    'max_redirects' => 5,
                ],
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ]
            ]);

            // Open source URL for reading
            $source_fp = @fopen($source->url, 'r', false, $ctx);

            if (!$source_fp) {
                $this->error("Failed to open URL for reading");
                $this->error("Error: " . error_get_last()['message'] ?? 'Unknown error');
                return 1;
            }

            // Open destination file for writing
            $dest_fp = fopen($tempFile, 'w');

            if (!$dest_fp) {
                fclose($source_fp);
                $this->error("Failed to open destination file for writing");
                return 1;
            }

            // Stream copy with progress indicator
            $bytesDownloaded = 0;
            $lastProgress = 0;

            while (!feof($source_fp)) {
                $chunk = fread($source_fp, 8192);  // Read 8KB at a time
                if ($chunk === false) {
                    break;
                }

                fwrite($dest_fp, $chunk);
                $bytesDownloaded += strlen($chunk);

                // Show progress every 10MB
                $currentMB = round($bytesDownloaded / 1024 / 1024);
                if ($currentMB > $lastProgress && $currentMB % 10 == 0) {
                    $this->info("Downloaded: {$currentMB} MB...");
                    $lastProgress = $currentMB;
                }
            }

            fclose($source_fp);
            fclose($dest_fp);

            $duration = round(microtime(true) - $startTime, 2);

            // Check if file was created and has content
            if (!file_exists($tempFile)) {
                $this->error("Download failed: File was not created");
                return 1;
            }

            $fileSize = filesize($tempFile);

            if ($fileSize === 0) {
                $this->error("Download failed: File is empty");
                unlink($tempFile);
                return 1;
            }

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
