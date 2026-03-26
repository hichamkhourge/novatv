<?php

namespace App\Services;

use App\Models\IptvUser;

class M3UGeneratorService
{
    private M3UParserService $parserService;

    public function __construct(M3UParserService $parserService)
    {
        $this->parserService = $parserService;
    }

    public function generate(IptvUser $user): string
    {
        // Get channels from user's assigned M3U source
        if (!$user->m3u_source_id) {
            // No M3U source assigned, return empty playlist
            return $this->buildM3U([], $user);
        }

        $channels = $this->parserService->getChannelsBySource($user->m3u_source_id);

        return $this->buildM3U($channels, $user);
    }

    private function buildM3U(array $channels, IptvUser $user): string
    {
        // Enhanced M3U header with attributes for better player compatibility
        $m3u = "#EXTM3U x-tvg-url=\"\" url-tvg=\"\"\n";

        foreach ($channels as $channel) {
            $tvgId = $this->sanitizeAttribute($channel['tvg_id'] ?? '');
            $tvgName = $this->sanitizeAttribute($channel['tvg_name'] ?? '');
            $tvgLogo = $this->sanitizeAttribute($channel['tvg_logo'] ?? '');
            $groupTitle = $this->sanitizeAttribute($channel['group_title'] ?? 'Uncategorized');
            $name = $this->sanitizeAttribute($channel['name'] ?? 'Unknown');
            $url = $this->buildStreamUrl($channel, $user);

            // Build EXTINF line with all attributes for iboplayer compatibility
            $extinf = sprintf(
                '#EXTINF:-1 tvg-id="%s" tvg-name="%s" tvg-logo="%s" group-title="%s",%s',
                $tvgId,
                $tvgName,
                $tvgLogo,
                $groupTitle,
                $name
            );

            $m3u .= $extinf . "\n";
            $m3u .= $url . "\n";
        }

        return $m3u;
    }

    /**
     * Sanitize attribute values to prevent issues with special characters
     */
    private function sanitizeAttribute(string $value): string
    {
        // Remove quotes and potentially problematic characters
        $value = str_replace(['"', "\n", "\r"], ['', '', ''], $value);
        return trim($value);
    }

    private function buildStreamUrl(array $channel, IptvUser $user): string
    {
        // Check if user's M3U source is configured for direct URLs
        if ($user->m3uSource && $user->m3uSource->use_direct_urls) {
            // Return original URL directly from source
            return $channel['url'] ?? '';
        }

        // Generate proxied URL (default behavior)
        // Generate stream ID from channel URL or tvg-id
        $streamId = md5($channel['url'] ?? $channel['tvg_id'] ?? rand());

        // Build URL: /hls/{username}/{password}/{stream_id}.m3u8
        // Use HLS format for maximum compatibility with IPTV apps (IBO Player, IPTV Smarters Pro, etc.)
        $baseUrl = config('app.url');

        return sprintf(
            '%s/hls/%s/%s/%s.m3u8',
            rtrim($baseUrl, '/'),
            urlencode($user->username),
            urlencode($user->password),
            $streamId
        );
    }
}
