<?php

namespace App\Http\Controllers;

use App\Models\IptvUser;
use App\Services\M3UParserService;
use App\Services\HlsTranscoderService;
use App\Services\ConnectionTrackerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class HlsController extends Controller
{
    public function __construct(
        private M3UParserService $m3uParser,
        private HlsTranscoderService $hlsTranscoder,
        private ConnectionTrackerService $connectionTracker
    ) {}

    /**
     * Serve HLS playlist (.m3u8)
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

        // Check if FFmpeg is available
        if (!$this->hlsTranscoder->isFfmpegAvailable()) {
            Log::error('FFmpeg is not installed or not available');
            abort(500, 'FFmpeg is not available. Please install FFmpeg to use HLS streaming.');
        }

        // Get upstream URL
        $upstreamUrl = $this->getUpstreamUrl($user, $streamId);

        if (!$upstreamUrl) {
            abort(404, 'Stream not found');
        }

        // If upstream is already HLS, proxy it directly (no transcoding needed)
        if ($this->isHlsStream($upstreamUrl)) {
            return $this->proxyHlsPlaylist($upstreamUrl, $request);
        }

        // Start HLS transcoding (if not already started)
        try {
            $playlistPath = $this->hlsTranscoder->startStream($streamId, $upstreamUrl);

            // Wait for playlist to be generated
            if (!$this->hlsTranscoder->waitForPlaylist($streamId)) {
                Log::error("Playlist generation timeout for stream {$streamId}");
                abort(502, 'Failed to generate HLS playlist. Please try again.');
            }

            // Read playlist content
            $playlistContent = File::get($playlistPath);

            // Rewrite URLs to point to our segment endpoint
            $playlistContent = $this->rewritePlaylistUrls($playlistContent, $username, $password, $streamId);

            return response($playlistContent, 200, [
                'Content-Type' => 'application/vnd.apple.mpegurl',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0',
                'Access-Control-Allow-Origin' => '*',
                'Access-Control-Allow-Methods' => 'GET, HEAD, OPTIONS',
            ]);

        } catch (\Exception $e) {
            Log::error('HLS transcoding error', [
                'stream_id' => $streamId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            abort(502, 'Failed to start HLS transcoding: ' . $e->getMessage());
        }
    }

    /**
     * Serve HLS segment (.ts file)
     */
    public function segment(Request $request, string $username, string $password, string $streamId, string $segment)
    {
        // Authenticate user
        $user = $this->authenticate($username, $password);

        if (!$user) {
            abort(403, 'Invalid credentials');
        }

        // Get segment path
        $segmentPath = $this->hlsTranscoder->getSegmentPath($streamId, $segment);

        // Wait a bit if segment doesn't exist yet (it might be being generated)
        $maxWait = 5; // 5 seconds
        $waited = 0;
        while (!File::exists($segmentPath) && $waited < $maxWait) {
            usleep(500000); // 0.5 seconds
            $waited += 0.5;
        }

        if (!File::exists($segmentPath)) {
            Log::warning("Segment not found", [
                'stream_id' => $streamId,
                'segment' => $segment,
                'path' => $segmentPath,
            ]);
            abort(404, 'Segment not found');
        }

        return response()->file($segmentPath, [
            'Content-Type' => 'video/mp2t',
            'Cache-Control' => 'public, max-age=10',
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, HEAD, OPTIONS',
        ]);
    }

    /**
     * Proxy an existing HLS stream (no transcoding needed)
     */
    private function proxyHlsPlaylist(string $streamUrl, Request $request)
    {
        try {
            $headers = [
                'User-Agent' => $request->header('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'),
            ];

            $response = \Illuminate\Support\Facades\Http::withHeaders($headers)
                ->withOptions([
                    'timeout' => 10,
                    'verify' => false,
                    'allow_redirects' => ['max' => 5],
                ])
                ->get($streamUrl);

            if (!$response->successful()) {
                Log::error('Failed to fetch HLS playlist', [
                    'url' => $streamUrl,
                    'status' => $response->status(),
                ]);
                abort($response->status(), 'Failed to fetch HLS playlist');
            }

            $playlistContent = $response->body();
            $baseUrl = $this->getBaseUrl($streamUrl);

            // Make URLs absolute (but don't proxy them - direct to source)
            $modifiedPlaylist = $this->makeUrlsAbsolute($playlistContent, $baseUrl);

            return response($modifiedPlaylist, 200, [
                'Content-Type' => 'application/vnd.apple.mpegurl',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0',
                'Access-Control-Allow-Origin' => '*',
            ]);

        } catch (\Exception $e) {
            Log::error('HLS proxy error', [
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
     * Rewrite playlist URLs to point to our segment endpoint
     */
    private function rewritePlaylistUrls(string $playlist, string $username, string $password, string $streamId): string
    {
        $lines = explode("\n", $playlist);
        $rewrittenLines = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // If it's a segment line (not a comment or empty)
            if (!empty($trimmed) && !str_starts_with($trimmed, '#')) {
                // Extract segment filename
                $segment = basename($trimmed);

                // Rewrite to point to our segment endpoint
                $line = config('app.url') . "/hls/{$username}/{$password}/{$streamId}/{$segment}";
            }

            $rewrittenLines[] = $line;
        }

        return implode("\n", $rewrittenLines);
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
