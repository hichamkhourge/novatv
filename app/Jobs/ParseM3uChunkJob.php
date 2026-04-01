<?php

namespace App\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class ParseM3uChunkJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120; // 2 minutes per chunk
    public int $tries = 3; // Retry up to 3 times

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $sourceId,
        public array $channels,
        public int $chunkIndex,
        public \Carbon\Carbon $syncStartedAt,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        try {
            // Upsert channels into database
            // Match on m3u_source_id + stream_url to avoid duplicates
            $upsertedCount = DB::table('channels')->upsert(
                $this->channels,
                ['m3u_source_id', 'stream_url'], // Unique keys
                ['stream_id', 'name', 'logo', 'category', 'epg_id', 'is_active', 'deleted_at', 'updated_at'] // Update columns
            );

            Log::debug("Parse M3U Chunk: Processed", [
                'source_id' => $this->sourceId,
                'chunk_index' => $this->chunkIndex,
                'channels_count' => count($this->channels),
                'upserted_count' => $upsertedCount,
            ]);

            // Update progress in Redis
            $this->incrementProgress();
        } catch (\Exception $e) {
            Log::error("Parse M3U Chunk: Failed", [
                'source_id' => $this->sourceId,
                'chunk_index' => $this->chunkIndex,
                'error' => $e->getMessage(),
            ]);

            // Increment failed counter
            $this->incrementProgress(true);

            // Re-throw to trigger batch failure if all retries exhausted
            if ($this->attempts() >= $this->tries) {
                throw $e;
            }

            // Release back to queue for retry
            $this->release(30); // Wait 30 seconds before retry
        }
    }

    /**
     * Increment progress counter in Redis
     */
    private function incrementProgress(bool $failed = false): void
    {
        $key = "m3u_sync:{$this->sourceId}";
        $current = json_decode(Redis::get($key) ?: '{}', true);

        $current['processed_chunks'] = ($current['processed_chunks'] ?? 0) + 1;

        if ($failed) {
            $current['failed'] = ($current['failed'] ?? 0) + count($this->channels);
        } else {
            $current['inserted'] = ($current['inserted'] ?? 0) + count($this->channels);
        }

        Redis::setex($key, 3600, json_encode($current));
    }
}
