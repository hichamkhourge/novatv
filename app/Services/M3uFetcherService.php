<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\M3uSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class M3uFetcherService
{
    protected M3UParserService $parser;

    public function __construct(M3UParserService $parser)
    {
        $this->parser = $parser;
    }

    /**
     * Fetch and sync channels from an M3U source
     */
    public function fetchAndSync(M3uSource $source): array
    {
        try {
            Log::info("M3uFetcher: Starting fetch for source {$source->name}");

            // Fetch M3U content
            $content = $this->fetchContent($source);

            if (!$content) {
                throw new \Exception('Failed to fetch M3U content');
            }

            // Parse M3U content
            $channels = $this->parser->parseM3UContent($content);

            if (empty($channels)) {
                Log::warning("M3uFetcher: No channels found in source {$source->name}");
                return [
                    'added' => 0,
                    'updated' => 0,
                    'removed' => 0,
                    'total' => 0,
                ];
            }

            // Sync channels to database
            $stats = $this->syncChannels($source, $channels);

            // Update last_fetched_at
            $source->update(['last_fetched_at' => now()]);

            Log::info("M3uFetcher: Completed for source {$source->name}", $stats);

            return $stats;
        } catch (\Exception $e) {
            Log::error("M3uFetcher: Failed for source {$source->name}: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Fetch M3U content from source
     */
    protected function fetchContent(M3uSource $source): ?string
    {
        if ($source->source_type === 'file' && $source->file_path) {
            return $this->fetchFromFile($source->file_path);
        }

        if ($source->source_type === 'url' && $source->url) {
            return $this->fetchFromUrl($source->url);
        }

        throw new \Exception('Invalid source type or missing URL/path');
    }

    /**
     * Fetch M3U content from file
     */
    protected function fetchFromFile(string $filePath): ?string
    {
        if (!file_exists($filePath)) {
            throw new \Exception("File not found: {$filePath}");
        }

        return file_get_contents($filePath);
    }

    /**
     * Fetch M3U content from URL
     */
    protected function fetchFromUrl(string $url): ?string
    {
        $response = \Illuminate\Support\Facades\Http::timeout(60)
            ->withOptions([
                'verify' => false, // For self-signed certificates
                'stream' => true,  // For large files
            ])
            ->get($url);

        if (!$response->successful()) {
            throw new \Exception("HTTP error {$response->status()} for URL: {$url}");
        }

        return $response->body();
    }

    /**
     * Sync channels to database
     */
    protected function syncChannels(M3uSource $source, array $channels): array
    {
        $stats = [
            'added' => 0,
            'updated' => 0,
            'removed' => 0,
            'total' => count($channels),
        ];

        $processedIds = [];

        DB::beginTransaction();

        try {
            foreach ($channels as $channelData) {
                // Create unique identifier from tvg-id or name
                $identifier = $channelData['tvg-id'] ?? $channelData['name'];

                // Find or create channel
                $channel = Channel::where('m3u_source_id', $source->id)
                    ->where(function ($query) use ($channelData, $identifier) {
                        if (!empty($channelData['tvg-id'])) {
                            $query->where('tvg_id', $channelData['tvg-id']);
                        } else {
                            $query->where('name', $channelData['name']);
                        }
                    })
                    ->first();

                $data = [
                    'name' => $channelData['name'],
                    'tvg_id' => $channelData['tvg-id'] ?? null,
                    'tvg_name' => $channelData['tvg-name'] ?? null,
                    'tvg_logo' => $channelData['tvg-logo'] ?? null,
                    'group_name' => $channelData['group-title'] ?? null,
                    'stream_url' => $channelData['url'],
                    'm3u_source_id' => $source->id,
                    'is_active' => true,
                ];

                if ($channel) {
                    // Update existing
                    $channel->update($data);
                    $stats['updated']++;
                } else {
                    // Create new
                    $channel = Channel::create($data);
                    $stats['added']++;
                }

                $processedIds[] = $channel->id;
            }

            // Remove channels that no longer exist in the source
            $removed = Channel::where('m3u_source_id', $source->id)
                ->whereNotIn('id', $processedIds)
                ->delete();

            $stats['removed'] = $removed;

            DB::commit();

            return $stats;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Get unique group names from a source
     */
    public function getGroupNames(M3uSource $source): array
    {
        return Channel::where('m3u_source_id', $source->id)
            ->whereNotNull('group_name')
            ->distinct()
            ->pluck('group_name')
            ->sort()
            ->values()
            ->toArray();
    }

    /**
     * Get channel count by group for a source
     */
    public function getChannelCountsByGroup(M3uSource $source): array
    {
        return Channel::where('m3u_source_id', $source->id)
            ->select('group_name', DB::raw('count(*) as count'))
            ->groupBy('group_name')
            ->orderBy('count', 'desc')
            ->get()
            ->pluck('count', 'group_name')
            ->toArray();
    }
}
