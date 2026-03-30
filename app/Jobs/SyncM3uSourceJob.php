<?php

namespace App\Jobs;

use App\Models\M3uSource;
use App\Services\M3uDownloader;
use App\Services\M3uParser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class SyncM3uSourceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900; // 15 minutes
    public int $tries = 1; // Don't retry - let admin manually retry

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $sourceId,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $source = M3uSource::find($this->sourceId);

        if (!$source) {
            Log::error("Sync M3U: Source not found", ['source_id' => $this->sourceId]);
            return;
        }

        // Set status to syncing
        $source->update([
            'status' => 'syncing',
            'error_message' => null,
        ]);

        $tempFilePath = null;
        $syncStartedAt = now();

        // Initialize progress tracking in Redis
        Redis::setex(
            "m3u_sync:{$this->sourceId}",
            3600, // 1 hour TTL
            json_encode([
                'total_chunks' => 0,
                'processed_chunks' => 0,
                'inserted' => 0,
                'updated' => 0,
                'failed' => 0,
                'started_at' => $syncStartedAt->toIso8601String(),
            ])
        );

        try {
            // Step 1: Download M3U file
            Log::info("Sync M3U: Starting download", [
                'source_id' => $this->sourceId,
                'url' => $source->url,
            ]);

            $downloader = new M3uDownloader();
            $tempFilePath = $downloader->download($this->sourceId, $source->url);

            // Step 2: Parse M3U file in chunks
            Log::info("Sync M3U: Starting parse", [
                'source_id' => $this->sourceId,
                'file' => $tempFilePath,
            ]);

            $parser = new M3uParser();
            $chunkJobs = [];
            $chunkIndex = 0;

            foreach ($parser->parseInChunks($tempFilePath, $this->sourceId, 500) as $chunk) {
                $chunkJobs[] = new ParseM3uChunkJob($this->sourceId, $chunk, $chunkIndex, $syncStartedAt);
                $chunkIndex++;
            }

            if (empty($chunkJobs)) {
                throw new \Exception("No channels found in M3U file");
            }

            // Update total chunks in progress tracker
            $this->updateProgress(['total_chunks' => count($chunkJobs)]);

            Log::info("Sync M3U: Dispatching batch", [
                'source_id' => $this->sourceId,
                'total_chunks' => count($chunkJobs),
            ]);

            // Step 3: Dispatch batch of chunk parsing jobs
            $batch = Bus::batch($chunkJobs)
                ->name("Sync M3U Source {$this->sourceId}")
                ->then(function () use ($source, $tempFilePath, $syncStartedAt) {
                    $this->onBatchComplete($source, $tempFilePath, $syncStartedAt);
                })
                ->catch(function (\Throwable $e) use ($source, $tempFilePath) {
                    $this->onBatchFailed($source, $tempFilePath, $e);
                })
                ->dispatch();

            Log::info("Sync M3U: Batch dispatched", [
                'source_id' => $this->sourceId,
                'batch_id' => $batch->id,
            ]);
        } catch (\Exception $e) {
            Log::error("Sync M3U: Failed", [
                'source_id' => $this->sourceId,
                'error' => $e->getMessage(),
            ]);

            // Update source status to error
            $source->update([
                'status' => 'error',
                'error_message' => $e->getMessage(),
            ]);

            // Cleanup temp file
            if ($tempFilePath) {
                (new M3uDownloader())->cleanup($tempFilePath);
            }

            // Clear Redis progress
            Redis::del("m3u_sync:{$this->sourceId}");
        }
    }

    /**
     * Handle successful batch completion
     */
    private function onBatchComplete(M3uSource $source, string $tempFilePath, \Carbon\Carbon $syncStartedAt): void
    {
        Log::info("Sync M3U: Batch completed", ['source_id' => $source->id]);

        try {
            // Soft-delete channels that weren't updated in this sync
            // (they no longer exist in the M3U file)
            $deletedCount = $source->channels()
                ->where('updated_at', '<', $syncStartedAt)
                ->whereNull('deleted_at')
                ->update(['deleted_at' => now()]);

            // Hard-delete channels that have been soft-deleted for > 24 hours
            $purgedCount = $source->channels()
                ->where('deleted_at', '<', now()->subDay())
                ->forceDelete();

            // Count active channels
            $activeCount = $source->channels()->active()->count();

            // Update source status
            $source->update([
                'status' => 'active',
                'channels_count' => $activeCount,
                'last_synced_at' => now(),
                'error_message' => null,
            ]);

            Log::info("Sync M3U: Sync completed successfully", [
                'source_id' => $source->id,
                'channels_count' => $activeCount,
                'deleted_count' => $deletedCount,
                'purged_count' => $purgedCount,
            ]);
        } catch (\Exception $e) {
            Log::error("Sync M3U: Post-processing failed", [
                'source_id' => $source->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Cleanup temp file
        (new M3uDownloader())->cleanup($tempFilePath);

        // Clear Redis progress (keep for 5 minutes for UI to read)
        Redis::expire("m3u_sync:{$source->id}", 300);
    }

    /**
     * Handle batch failure
     */
    private function onBatchFailed(M3uSource $source, ?string $tempFilePath, \Throwable $e): void
    {
        Log::error("Sync M3U: Batch failed", [
            'source_id' => $source->id,
            'error' => $e->getMessage(),
        ]);

        $source->update([
            'status' => 'error',
            'error_message' => "Batch processing failed: {$e->getMessage()}",
        ]);

        // Cleanup temp file
        if ($tempFilePath) {
            (new M3uDownloader())->cleanup($tempFilePath);
        }

        // Clear Redis progress
        Redis::del("m3u_sync:{$source->id}");
    }

    /**
     * Update progress in Redis
     */
    private function updateProgress(array $updates): void
    {
        $key = "m3u_sync:{$this->sourceId}";
        $current = json_decode(Redis::get($key) ?: '{}', true);
        $updated = array_merge($current, $updates);
        Redis::setex($key, 3600, json_encode($updated));
    }
}
