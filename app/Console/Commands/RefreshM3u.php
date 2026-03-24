<?php

namespace App\Console\Commands;

use App\Models\ChannelGroup;
use App\Models\M3uSource;
use App\Services\M3UParserService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class RefreshM3u extends Command
{
    protected $signature = 'iptv:refresh-m3u';
    protected $description = 'Fetch and cache M3U channels from all active sources';

    public function handle(M3UParserService $parserService)
    {
        $this->info('Starting M3U refresh...');

        $sources = M3uSource::where('is_active', true)->get();

        if ($sources->isEmpty()) {
            $this->warn('No active M3U sources found.');
            return 0;
        }

        $totalChannels = 0;
        $allGroups = [];

        foreach ($sources as $source) {
            $this->info("Fetching from: {$source->name}");

            $channels = $parserService->fetchAndCache($source->url);
            $channelCount = count($channels);

            $this->info("  ✓ Fetched {$channelCount} channels");

            // Collect unique groups
            foreach ($channels as $channel) {
                if (!empty($channel['group_title'])) {
                    $allGroups[$channel['group_title']] = true;
                }
            }

            $totalChannels += $channelCount;

            // Update last_fetched_at
            $source->update(['last_fetched_at' => now()]);
        }

        // Sync channel groups
        $newGroups = 0;
        foreach (array_keys($allGroups) as $groupName) {
            $slug = Str::slug($groupName);
            $created = ChannelGroup::firstOrCreate(
                ['slug' => $slug],
                ['name' => $groupName]
            );

            if ($created->wasRecentlyCreated) {
                $newGroups++;
                $this->info("  + New group: {$groupName}");
            }
        }

        $this->info("✓ Refresh complete!");
        $this->info("  Total channels: {$totalChannels}");
        $this->info("  Total groups: " . count($allGroups));
        $this->info("  New groups: {$newGroups}");

        return 0;
    }
}
