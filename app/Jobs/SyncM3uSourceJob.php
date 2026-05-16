<?php

namespace App\Jobs;

use App\Models\AccountChannelPreference;
use App\Models\Channel;
use App\Models\ChannelGroup;
use App\Models\M3uSource;
use App\Services\M3uParser;
use App\Support\ChannelGroupAdultClassifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Smart M3U source sync job that preserves user channel preferences.
 *
 * Detects:
 * - New channels → Insert and mark as new
 * - Removed channels → Mark as inactive (preserve preferences)
 * - Modified channels → Update stream_url, preserve preferences
 */
class SyncM3uSourceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600; // 10 minutes

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $sourceId
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $source = M3uSource::find($this->sourceId);

        if (!$source) {
            Log::error('SyncM3uSourceJob: Source not found', ['source_id' => $this->sourceId]);
            return;
        }

        // Only sync M3U sources (URL or file type)
        if ($source->source_type === 'xtream') {
            Log::info('SyncM3uSourceJob: Skipping Xtream source (use ImportXtreamJob instead)', [
                'source_id' => $source->id,
                'name' => $source->name,
            ]);
            return;
        }

        Log::info('SyncM3uSourceJob: Starting sync', [
            'source_id' => $source->id,
            'name' => $source->name,
            'source_type' => $source->source_type,
        ]);

        $source->update(['status' => 'syncing', 'error_message' => null]);

        try {
            $this->syncSource($source);

            $source->update([
                'status' => 'idle',
                'last_synced_at' => now(),
                'error_message' => null,
            ]);

            Log::info('SyncM3uSourceJob: Sync completed', [
                'source_id' => $source->id,
                'channels_count' => $source->channels_count,
            ]);
        } catch (\Throwable $e) {
            $source->update([
                'status' => 'error',
                'error_message' => $e->getMessage(),
            ]);

            Log::error('SyncM3uSourceJob: Sync failed', [
                'source_id' => $source->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Sync M3U source with smart conflict resolution.
     */
    protected function syncSource(M3uSource $source): void
    {
        // Get M3U content
        $m3uContent = $source->source_type === 'file'
            ? file_get_contents($source->getFullFilePath())
            : file_get_contents($source->url);

        if (!$m3uContent) {
            throw new \RuntimeException('Failed to fetch M3U content');
        }

        // Parse M3U
        $parser = new M3uParser();
        $parsed = $parser->parse($m3uContent);

        // Get existing channels keyed by stream_url for fast lookup
        $existingChannels = Channel::where('m3u_source_id', $source->id)
            ->get()
            ->keyBy('stream_url');

        $processedUrls = [];
        $newChannels = [];
        $updatedChannels = [];
        $groupsMap = [];

        foreach ($parsed as $entry) {
            $streamUrl = $entry['url'];
            $processedUrls[] = $streamUrl;

            // Get or create channel group
            $groupName = $entry['attrs']['group-title'] ?? 'Uncategorized';

            if (!isset($groupsMap[$groupName])) {
                $slug = Str::slug($groupName);

                // Prevent collisions with other sources
                $slug = "{$source->id}-{$slug}";

                $group = ChannelGroup::firstOrCreate(
                    ['slug' => $slug],
                    [
                        'name' => $groupName,
                        'is_adult' => ChannelGroupAdultClassifier::isAdult($groupName),
                        'is_active' => true,
                        'sort_order' => 999,
                    ]
                );

                $groupsMap[$groupName] = $group->id;
            }

            $groupId = $groupsMap[$groupName];
            $channelName = $entry['name'];
            $tvgId = $entry['attrs']['tvg-id'] ?? null;
            $tvgName = $entry['attrs']['tvg-name'] ?? null;
            $logoUrl = $entry['attrs']['tvg-logo'] ?? null;

            if ($existingChannels->has($streamUrl)) {
                // Channel exists - update if needed
                $channel = $existingChannels->get($streamUrl);
                $updated = false;

                if ($channel->name !== $channelName) {
                    $channel->name = $channelName;
                    $updated = true;
                }

                if ($channel->channel_group_id !== $groupId) {
                    $channel->channel_group_id = $groupId;
                    $updated = true;
                }

                if ($channel->tvg_id !== $tvgId) {
                    $channel->tvg_id = $tvgId;
                    $updated = true;
                }

                if ($channel->tvg_name !== $tvgName) {
                    $channel->tvg_name = $tvgName;
                    $updated = true;
                }

                if ($channel->logo_url !== $logoUrl) {
                    $channel->logo_url = $logoUrl;
                    $updated = true;
                }

                if (!$channel->is_active) {
                    $channel->is_active = true;
                    $updated = true;
                }

                if ($updated) {
                    $channel->save();
                    $updatedChannels[] = $channel->id;
                }
            } else {
                // New channel - insert
                $newChannels[] = [
                    'channel_group_id' => $groupId,
                    'm3u_source_id' => $source->id,
                    'name' => $channelName,
                    'stream_url' => $streamUrl,
                    'logo_url' => $logoUrl,
                    'tvg_id' => $tvgId,
                    'tvg_name' => $tvgName,
                    'sort_order' => 0,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        // Batch insert new channels
        if (!empty($newChannels)) {
            Channel::insert($newChannels);
            Log::info('SyncM3uSourceJob: Inserted new channels', [
                'source_id' => $source->id,
                'count' => count($newChannels),
            ]);
        }

        // Mark removed channels as inactive (preserve for preference history)
        $removedCount = Channel::where('m3u_source_id', $source->id)
            ->whereNotIn('stream_url', $processedUrls)
            ->where('is_active', true)
            ->update(['is_active' => false, 'updated_at' => now()]);

        if ($removedCount > 0) {
            Log::info('SyncM3uSourceJob: Marked channels as inactive', [
                'source_id' => $source->id,
                'count' => $removedCount,
            ]);
        }

        // Update source channel count
        $source->channels_count = Channel::where('m3u_source_id', $source->id)
            ->where('is_active', true)
            ->count();
        $source->save();

        // Log summary
        Log::info('SyncM3uSourceJob: Sync summary', [
            'source_id' => $source->id,
            'new_channels' => count($newChannels),
            'updated_channels' => count($updatedChannels),
            'removed_channels' => $removedCount,
            'total_active' => $source->channels_count,
        ]);
    }
}
