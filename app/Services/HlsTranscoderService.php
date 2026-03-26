<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

class HlsTranscoderService
{
    private string $hlsBasePath;
    private string $ffmpegPath = 'ffmpeg';
    private int $segmentDuration = 4;
    private int $playlistSize = 6;
    private int $maxWaitTime = 15; // Max seconds to wait for playlist generation

    public function __construct()
    {
        // Use /tmp/hls for HLS segments storage
        $this->hlsBasePath = storage_path('app/hls');

        // Create base directory if it doesn't exist
        if (!File::exists($this->hlsBasePath)) {
            File::makeDirectory($this->hlsBasePath, 0755, true);
        }
    }

    /**
     * Start HLS transcoding for a stream
     */
    public function startStream(string $streamId, string $upstreamUrl): string
    {
        $outputDir = "{$this->hlsBasePath}/{$streamId}";

        // Check if already transcoding
        if ($this->isTranscoding($streamId)) {
            Log::info("Stream {$streamId} already transcoding");
            return "{$outputDir}/playlist.m3u8";
        }

        // Create output directory
        if (!File::exists($outputDir)) {
            File::makeDirectory($outputDir, 0755, true);
        }

        // Build FFmpeg command
        $command = $this->buildFfmpegCommand($upstreamUrl, $outputDir);

        // Start FFmpeg in background
        $pid = $this->startBackgroundProcess($command);

        if ($pid <= 0) {
            Log::error("Failed to start FFmpeg process for stream {$streamId}");
            throw new \Exception("Failed to start HLS transcoding");
        }

        // Store process info in cache (expires after 2 hours)
        Cache::put("hls:process:{$streamId}", [
            'pid' => $pid,
            'started_at' => now()->toIso8601String(),
            'upstream_url' => $upstreamUrl,
            'output_dir' => $outputDir,
        ], now()->addHours(2));

        Log::info("Started HLS transcoding", [
            'stream_id' => $streamId,
            'pid' => $pid,
            'upstream_url' => $upstreamUrl,
        ]);

        return "{$outputDir}/playlist.m3u8";
    }

    /**
     * Stop HLS transcoding for a stream
     */
    public function stopStream(string $streamId): void
    {
        $processInfo = Cache::get("hls:process:{$streamId}");

        if ($processInfo && isset($processInfo['pid'])) {
            $pid = $processInfo['pid'];

            // Kill the process
            @exec("kill {$pid} 2>/dev/null");

            // Remove from cache
            Cache::forget("hls:process:{$streamId}");

            // Clean up files
            $outputDir = $processInfo['output_dir'] ?? "{$this->hlsBasePath}/{$streamId}";
            if (File::exists($outputDir)) {
                File::deleteDirectory($outputDir);
            }

            Log::info("Stopped HLS transcoding", [
                'stream_id' => $streamId,
                'pid' => $pid,
            ]);
        }
    }

    /**
     * Check if stream is currently transcoding
     */
    public function isTranscoding(string $streamId): bool
    {
        $processInfo = Cache::get("hls:process:{$streamId}");

        if (!$processInfo || !isset($processInfo['pid'])) {
            return false;
        }

        // Check if process is still running
        $pid = $processInfo['pid'];
        $output = shell_exec("ps -p {$pid} -o pid= 2>/dev/null");

        $isRunning = !empty(trim($output));

        // If process is not running but cache says it is, clean up
        if (!$isRunning) {
            Cache::forget("hls:process:{$streamId}");
        }

        return $isRunning;
    }

    /**
     * Get playlist path for a stream
     */
    public function getPlaylistPath(string $streamId): string
    {
        return "{$this->hlsBasePath}/{$streamId}/playlist.m3u8";
    }

    /**
     * Get segment path for a stream
     */
    public function getSegmentPath(string $streamId, string $segment): string
    {
        return "{$this->hlsBasePath}/{$streamId}/{$segment}";
    }

    /**
     * Wait for playlist to be generated
     */
    public function waitForPlaylist(string $streamId): bool
    {
        $playlistPath = $this->getPlaylistPath($streamId);
        $waited = 0;

        while (!File::exists($playlistPath) && $waited < $this->maxWaitTime) {
            usleep(500000); // Sleep 0.5 seconds
            $waited += 0.5;
        }

        return File::exists($playlistPath);
    }

    /**
     * Cleanup old streams that have been running for too long
     */
    public function cleanupOldStreams(): int
    {
        $cleaned = 0;
        $allKeys = [];

        // Get all HLS process keys from cache
        // Note: This is a simplified approach. In production, you might want to use Redis SCAN
        try {
            // Try to get all cache keys starting with hls:process:
            // This will work with array cache driver
            foreach (Cache::getStore()->getMemcached()->getAllKeys() as $key) {
                if (str_starts_with($key, config('cache.prefix') . ':hls:process:')) {
                    $allKeys[] = str_replace(config('cache.prefix') . ':', '', $key);
                }
            }
        } catch (\Exception $e) {
            // If cache driver doesn't support getAllKeys, skip cleanup
            Log::warning('Unable to get cache keys for cleanup', ['error' => $e->getMessage()]);
            return 0;
        }

        foreach ($allKeys as $key) {
            $processInfo = Cache::get($key);

            if ($processInfo && isset($processInfo['started_at'])) {
                $startedAt = \Carbon\Carbon::parse($processInfo['started_at']);

                // Cleanup streams older than 2 hours
                if (now()->diffInHours($startedAt) >= 2) {
                    $streamId = str_replace('hls:process:', '', $key);
                    $this->stopStream($streamId);
                    $cleaned++;
                }
            }
        }

        return $cleaned;
    }

    /**
     * Check if FFmpeg is available
     */
    public function isFfmpegAvailable(): bool
    {
        $output = shell_exec('which ffmpeg 2>/dev/null');
        return !empty(trim($output));
    }

    /**
     * Build FFmpeg command for HLS transcoding
     */
    private function buildFfmpegCommand(string $upstreamUrl, string $outputDir): string
    {
        // Use -c copy to avoid transcoding (just remux TS to HLS segments)
        // This is much faster and uses minimal CPU
        return sprintf(
            '%s -i "%s" ' .
            '-c:v copy -c:a copy ' . // Copy codecs (no transcoding)
            '-f hls ' .
            '-hls_time %d ' . // Segment duration in seconds
            '-hls_list_size %d ' . // Number of segments in playlist
            '-hls_flags delete_segments+append_list ' . // Delete old segments and append to playlist
            '-hls_segment_filename "%s/segment_%%03d.ts" ' .
            '-y ' . // Overwrite output files
            '"%s/playlist.m3u8" ' .
            '> /dev/null 2>&1 & echo $!', // Run in background and return PID
            $this->ffmpegPath,
            $upstreamUrl,
            $this->segmentDuration,
            $this->playlistSize,
            $outputDir,
            $outputDir
        );
    }

    /**
     * Start a background process and return its PID
     */
    private function startBackgroundProcess(string $command): int
    {
        // Execute command and capture PID
        $output = shell_exec($command);
        $pid = (int) trim($output);

        // Wait a moment to ensure process started
        usleep(500000); // 0.5 seconds

        // Verify process is actually running
        if ($pid > 0) {
            $check = shell_exec("ps -p {$pid} -o pid= 2>/dev/null");
            if (empty(trim($check))) {
                return 0;
            }
        }

        return $pid;
    }
}
