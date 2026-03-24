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
        $m3u = "#EXTM3U\n";

        foreach ($channels as $channel) {
            $tvgId = $channel['tvg_id'] ?? '';
            $tvgName = $channel['tvg_name'] ?? '';
            $tvgLogo = $channel['tvg_logo'] ?? '';
            $groupTitle = $channel['group_title'] ?? '';
            $name = $channel['name'] ?? 'Unknown';
            $url = $this->buildStreamUrl($channel, $user);

            $m3u .= sprintf(
                '#EXTINF:-1 tvg-id="%s" tvg-name="%s" tvg-logo="%s" group-title="%s",%s' . "\n",
                $tvgId,
                $tvgName,
                $tvgLogo,
                $groupTitle,
                $name
            );
            $m3u .= $url . "\n";
        }

        return $m3u;
    }

    private function buildStreamUrl(array $channel, IptvUser $user): string
    {
        // Generate stream ID from channel URL or tvg-id
        $streamId = md5($channel['url'] ?? $channel['tvg_id'] ?? rand());

        // Build URL: /live/{username}/{password}/{stream_id}.ts
        $baseUrl = config('app.url');

        return sprintf(
            '%s/live/%s/%s/%s.ts',
            rtrim($baseUrl, '/'),
            urlencode($user->username),
            urlencode($user->password),
            $streamId
        );
    }
}
