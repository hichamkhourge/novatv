<?php

namespace App\Services;

use App\Models\M3uSource;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class M3UParserService
{
    private const CACHE_KEY = 'm3u_channels';
    private const CACHE_TTL = 1800; // 30 minutes (reduced from 1 hour)
    private const LARGE_FILE_SIZE_MB = 10; // If file > 10MB, download it first

    public function fetchAndCache(string $sourceUrl): array
    {
        // Increase memory limit for large M3U files
        $oldLimit = ini_get('memory_limit');
        ini_set('memory_limit', '512M');

        try {
            // Try to detect file size first
            $headers = get_headers($sourceUrl, true);
            $contentLength = $headers['Content-Length'] ?? 0;

            // If file is large (>10MB), download to temp file first
            if ($contentLength > (self::LARGE_FILE_SIZE_MB * 1024 * 1024)) {
                Log::info("Large M3U detected ({$contentLength} bytes), using file-based parsing");
                return $this->fetchLargeFileAndCache($sourceUrl);
            }

            // For smaller files, use HTTP client with no timeout for fetching
            $response = Http::timeout(0)->get($sourceUrl);

            if (!$response->successful()) {
                Log::error("Failed to fetch M3U from {$sourceUrl}", [
                    'status' => $response->status(),
                ]);
                ini_set('memory_limit', $oldLimit);
                return [];
            }

            $content = $response->body();

            // Handle encoding and BOM
            $content = $this->normalizeContent($content);

            // Validate M3U format
            if (!str_starts_with($content, '#EXTM3U')) {
                Log::error("Invalid M3U format from {$sourceUrl}");
                ini_set('memory_limit', $oldLimit);
                return [];
            }

            $channels = $this->parseM3UFromString($content);

            // Cache channels
            Cache::put(self::CACHE_KEY, $channels, self::CACHE_TTL);

            ini_set('memory_limit', $oldLimit);
            return $channels;
        } catch (\Exception $e) {
            Log::error("Error fetching M3U from {$sourceUrl}: {$e->getMessage()}");
            ini_set('memory_limit', $oldLimit);
            return [];
        }
    }

    private function fetchLargeFileAndCache(string $sourceUrl): array
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'm3u_');

        try {
            // Download file in chunks using native PHP streams
            $source = fopen($sourceUrl, 'rb');
            $dest = fopen($tempFile, 'wb');

            if (!$source || !$dest) {
                throw new \Exception("Failed to open streams for download");
            }

            stream_copy_to_stream($source, $dest);
            fclose($source);
            fclose($dest);

            // Parse from file
            $channels = $this->parseM3UFromFile($tempFile);

            // Cache channels
            Cache::put(self::CACHE_KEY, $channels, self::CACHE_TTL);

            // Clean up
            @unlink($tempFile);

            return $channels;
        } catch (\Exception $e) {
            Log::error("Error downloading large M3U file: {$e->getMessage()}");
            @unlink($tempFile);
            return [];
        }
    }

    private function normalizeContent(string $content): string
    {
        // Strip BOM if present
        $content = ltrim($content, "\xEF\xBB\xBF");

        // Detect encoding and convert to UTF-8
        $encoding = mb_detect_encoding($content, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);
        if ($encoding && $encoding !== 'UTF-8') {
            $content = mb_convert_encoding($content, 'UTF-8', $encoding);
        }

        return $content;
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

                // Handle URL-based sources - use optimized fetching logic
                // Increase memory limit
                $oldLimit = ini_get('memory_limit');
                ini_set('memory_limit', '512M');

                // Check file size and use appropriate method
                $headers = @get_headers($source->url, true);
                $contentLength = $headers['Content-Length'] ?? 0;

                if ($contentLength > (self::LARGE_FILE_SIZE_MB * 1024 * 1024)) {
                    // Download large files
                    $tempFile = tempnam(sys_get_temp_dir(), 'm3u_');
                    $sourceStream = fopen($source->url, 'rb');
                    $dest = fopen($tempFile, 'wb');

                    if ($sourceStream && $dest) {
                        stream_copy_to_stream($sourceStream, $dest);
                        fclose($sourceStream);
                        fclose($dest);
                        $channels = $this->parseM3UFromFile($tempFile);
                        @unlink($tempFile);
                    } else {
                        ini_set('memory_limit', $oldLimit);
                        return [];
                    }
                } else {
                    // Small files can be fetched directly (no timeout)
                    $response = Http::timeout(0)->get($source->url);

                    if (!$response->successful()) {
                        Log::error("Failed to fetch M3U from source {$sourceId}", [
                            'url'    => $source->url,
                            'status' => $response->status(),
                        ]);
                        ini_set('memory_limit', $oldLimit);
                        return [];
                    }

                    $content = $this->normalizeContent($response->body());
                    $channels = $this->parseM3UFromString($content);
                }

                ini_set('memory_limit', $oldLimit);

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

    private function parseM3UFromString(string $content): array
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
