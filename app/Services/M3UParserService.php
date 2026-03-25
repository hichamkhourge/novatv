<?php

namespace App\Services;

use App\Models\M3uSource;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class M3UParserService
{
    private const CACHE_KEY = 'm3u_channels';
    private const CACHE_TTL = 3600; // 1 hour

    public function fetchAndCache(string $sourceUrl): array
    {
        try {
            $response = Http::timeout(30)->get($sourceUrl);

            if (!$response->successful()) {
                Log::error("Failed to fetch M3U from {$sourceUrl}", [
                    'status' => $response->status(),
                ]);
                return [];
            }

            $content = $response->body();
            $channels = $this->parseM3U($content);

            // Cache channels
            Cache::put(self::CACHE_KEY, $channels, self::CACHE_TTL);

            return $channels;
        } catch (\Exception $e) {
            Log::error("Error fetching M3U from {$sourceUrl}: {$e->getMessage()}");
            return [];
        }
    }

    public function getChannels(): array
    {
        $cached = Cache::get(self::CACHE_KEY);

        if ($cached !== null) {
            return $cached;
        }

        // Re-fetch from all active sources
        return $this->refreshAllSources();
    }

    public function getChannelsBySource(int $sourceId): array
    {
        $cacheKey = "m3u_channels_source_{$sourceId}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($sourceId) {
            $source = M3uSource::find($sourceId);

            if (!$source || !$source->is_active) {
                Log::warning("M3U source {$sourceId} not found or inactive");
                return [];
            }

            try {
                // Handle file-based sources
                if ($source->source_type === 'file' && $source->file_path) {
                    $filePath = storage_path('app/' . $source->file_path);

                    if (!file_exists($filePath)) {
                        Log::error("M3U file not found for source {$sourceId}", [
                            'file_path' => $filePath,
                        ]);
                        return [];
                    }

                    // Use stream reading for large files to avoid memory issues
                    $channels = $this->parseM3UFromFile($filePath);

                    // Update last_fetched_at
                    $source->update(['last_fetched_at' => now()]);

                    return $channels;
                }

                // Handle URL-based sources (existing logic)
                $response = Http::timeout(30)->get($source->url);

                if (!$response->successful()) {
                    Log::error("Failed to fetch M3U from source {$sourceId}", [
                        'url'    => $source->url,
                        'status' => $response->status(),
                    ]);
                    return [];
                }

                $channels = $this->parseM3U($response->body());

                // Update last_fetched_at
                $source->update(['last_fetched_at' => now()]);

                return $channels;
            } catch (\Exception $e) {
                Log::error("Error fetching M3U from source {$sourceId}: {$e->getMessage()}");
                return [];
            }
        });
    }

    public function getGroups(): array
    {
        $channels = $this->getChannels();
        $groups = [];

        foreach ($channels as $channel) {
            if (isset($channel['group_title']) && !in_array($channel['group_title'], $groups)) {
                $groups[] = $channel['group_title'];
            }
        }

        sort($groups);
        return $groups;
    }

    public function refreshAllSources(): array
    {
        $allChannels = [];
        $sources = M3uSource::where('is_active', true)->get();

        foreach ($sources as $source) {
            $channels = $this->fetchAndCache($source->url);
            $allChannels = array_merge($allChannels, $channels);

            $source->update(['last_fetched_at' => now()]);
        }

        Cache::put(self::CACHE_KEY, $allChannels, self::CACHE_TTL);

        return $allChannels;
    }

    private function parseM3U(string $content): array
    {
        $channels = [];
        $lines = explode("\n", $content);
        $currentChannel = null;

        foreach ($lines as $line) {
            $line = trim($line);

            if (str_starts_with($line, '#EXTINF:')) {
                $currentChannel = $this->parseExtInf($line);
            } elseif ($currentChannel && !str_starts_with($line, '#') && !empty($line)) {
                $currentChannel['url'] = $line;
                $channels[] = $currentChannel;
                $currentChannel = null;
            }
        }

        return $channels;
    }

    private function parseM3UFromFile(string $filePath): array
    {
        $channels = [];
        $currentChannel = null;
        $handle = fopen($filePath, 'r');

        if (!$handle) {
            return [];
        }

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);

            if (str_starts_with($line, '#EXTINF:')) {
                $currentChannel = $this->parseExtInf($line);
            } elseif ($currentChannel && !str_starts_with($line, '#') && !empty($line)) {
                $currentChannel['url'] = $line;
                $channels[] = $currentChannel;
                $currentChannel = null;
            }
        }

        fclose($handle);

        return $channels;
    }

    private function parseExtInf(string $line): array
    {
        $channel = [
            'tvg_id'      => '',
            'tvg_name'    => '',
            'tvg_logo'    => '',
            'group_title' => '',
            'name'        => '',
        ];

        if (preg_match('/tvg-id="([^"]*)"/', $line, $matches)) {
            $channel['tvg_id'] = $matches[1];
        }

        if (preg_match('/tvg-name="([^"]*)"/', $line, $matches)) {
            $channel['tvg_name'] = $matches[1];
        }

        if (preg_match('/tvg-logo="([^"]*)"/', $line, $matches)) {
            $channel['tvg_logo'] = $matches[1];
        }

        if (preg_match('/group-title="([^"]*)"/', $line, $matches)) {
            $channel['group_title'] = $matches[1];
        }

        if (preg_match('/,(.*)$/', $line, $matches)) {
            $channel['name'] = trim($matches[1]);
        }

        return $channel;
    }
}
