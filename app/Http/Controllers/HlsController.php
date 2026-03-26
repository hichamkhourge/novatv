<?php

namespace App\Http\Controllers;

use App\Models\IptvUser;
use App\Services\M3UParserService;
use App\Services\ConnectionTrackerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class HlsController extends Controller
{
    public function __construct(
        private M3UParserService $m3uParser,
        private ConnectionTrackerService $connectionTracker
    ) {}

    /**
     * Serve HLS playlist (.m3u8)
     * This creates a simple HLS wrapper that points to the actual stream
     */
    public function playlist(Request $request, string $username, string $password, string $streamId)
    {
        // Authenticate user
        $user = $this->authenticate($username, $password);

        if (!$user) {
            abort(403, 'Invalid credentials');
        }

        // Remove .m3u8 extension if present
        $streamId = str_replace('.m3u8', '', $streamId);

        // Register connection
        if (!$this->connectionTracker->register($user, $streamId, $request)) {
            abort(429, 'Maximum connections exceeded');
        }

        // Get upstream URL
        $upstreamUrl = $this->getUpstreamUrl($user, $streamId);

        if (!$upstreamUrl) {
            abort(404, 'Stream not found');
        }

        // If upstream is already HLS, proxy it directly
        if ($this->isHlsStream($upstreamUrl)) {
            return $this->proxyHlsPlaylist($upstreamUrl, $request);
        }

        // For non-HLS streams, create a simple HLS wrapper that points to our /live/ endpoint
        $liveStreamUrl = $this->buildLiveStreamUrl($username, $password, $streamId);

        $playlist = $this->generateSimpleHlsPlaylist($liveStreamUrl);

        return response($playlist, 200, [
            'Content-Type' => 'application/vnd.apple.mpegurl',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, HEAD, OPTIONS',
        ]);
    }

    /**
     * Generate a simple HLS playlist that points to the direct stream
     */
    private function generateSimpleHlsPlaylist(string $streamUrl): string
    {
        return "#EXTM3U\n" .
               "#EXT-X-VERSION:3\n" .
               "#EXT-X-TARGETDURATION:3600\n" .
               "#EXT-X-MEDIA-SEQUENCE:0\n" .
               "#EXTINF:-1,\n" .
               "{$streamUrl}\n" .
               "#EXT-X-ENDLIST\n";
    }

    /**
     * Build URL for the /live/ endpoint
     */
    private function buildLiveStreamUrl(string $username, string $password, string $streamId): string
    {
        $baseUrl = config('app.url');

        return sprintf(
            '%s/live/%s/%s/%s',
            rtrim($baseUrl, '/'),
            urlencode($username),
            urlencode($password),
            $streamId
        );
    }

    /**
     * Proxy an existing HLS playlist (when upstream already provides HLS)
     */
    private function proxyHlsPlaylist(string $streamUrl, Request $request)
    {
        try {
            $headers = [
                'User-Agent' => $request->header('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'),
            ];

            $response = Http::withHeaders($headers)
                ->withOptions([
                    'timeout' => 30,  // Increased from 10s to 30s for large playlists
                    'verify' => false,
                    'allow_redirects' => ['max' => 5],
                ])
                ->get($streamUrl);

            if (!$response->successful()) {
                \Log::error('Failed to fetch HLS playlist', [
                    'url' => $streamUrl,
                    'status' => $response->status(),
                ]);
                abort($response->status(), 'Failed to fetch HLS playlist');
            }

            $playlistContent = $response->body();
            $baseUrl = $this->getBaseUrl($streamUrl);

            // Make URLs absolute (reuse existing logic from PlaylistController)
            $modifiedPlaylist = $this->makeUrlsAbsolute($playlistContent, $baseUrl);

            return response($modifiedPlaylist, 200, [
                'Content-Type' => 'application/vnd.apple.mpegurl',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0',
                'Access-Control-Allow-Origin' => '*',
            ]);

        } catch (\Exception $e) {
            \Log::error('HLS proxy error', [
                'url' => $streamUrl,
                'error' => $e->getMessage(),
            ]);

            abort(502, 'Failed to proxy HLS playlist');
        }
    }

    /**
     * Authenticate user
     */
    private function authenticate(?string $username, ?string $password): ?IptvUser
    {
        if (!$username || !$password) {
            return null;
        }

        $user = IptvUser::where('username', $username)
            ->where('password', $password)
            ->first();

        if (!$user || !$user->isValid()) {
            return null;
        }

        return $user;
    }

    /**
     * Get upstream URL for a stream
     */
    private function getUpstreamUrl(IptvUser $user, string $streamId): ?string
    {
        if (!$user->m3u_source_id) {
            return null;
        }

        $channels = $this->m3uParser->getChannelsBySource($user->m3u_source_id);

        foreach ($channels as $channel) {
            $channelStreamId = md5($channel['url'] ?? $channel['tvg_id'] ?? '');
            if ($channelStreamId === $streamId) {
                return $channel['url'];
            }
        }

        return null;
    }

    /**
     * Check if URL is already an HLS stream
     */
    private function isHlsStream(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (!$path) {
            return false;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($extension, ['m3u8', 'm3u']);
    }

    /**
     * Make relative URLs absolute (for proxying existing HLS)
     */
    private function makeUrlsAbsolute(string $content, string $baseUrl): string
    {
        $lines = explode("\n", $content);
        $modifiedLines = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Skip comments and empty lines
            if (empty($trimmed) || str_starts_with($trimmed, '#')) {
                $modifiedLines[] = $line;
                continue;
            }

            // This is a URL line - make it absolute if relative
            $url = $trimmed;

            if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
                if (str_starts_with($url, '/')) {
                    // Absolute path - use scheme and host from base URL
                    $parsedBase = parse_url($baseUrl);
                    $url = $parsedBase['scheme'] . '://' . $parsedBase['host'] . $url;
                } else {
                    // Relative path - append to base URL
                    $url = rtrim($baseUrl, '/') . '/' . $url;
                }
            }

            $modifiedLines[] = $url;
        }

        return implode("\n", $modifiedLines);
    }

    /**
     * Get base URL from a full URL
     */
    private function getBaseUrl(string $url): string
    {
        $parsed = parse_url($url);
        $path = $parsed['path'] ?? '/';

        // Remove filename from path to get directory
        $directory = dirname($path);
        if ($directory === '.') {
            $directory = '/';
        }

        $baseUrl = $parsed['scheme'] . '://' . $parsed['host'];

        if (isset($parsed['port'])) {
            $baseUrl .= ':' . $parsed['port'];
        }

        $baseUrl .= $directory;

        return $baseUrl;
    }
}
