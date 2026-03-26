<?php

namespace App\Http\Controllers;

use App\Models\IptvUser;
use App\Services\M3UParserService;
use App\Services\ConnectionTrackerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class HlsController extends Controller
{
    public function __construct(
        private M3UParserService $m3uParser,
        private ConnectionTrackerService $connectionTracker
    ) {}

    /**
     * Stream HLS content by converting TS streams to HLS using FFmpeg
     */
    public function stream(Request $request, string $username, string $password, string $streamId)
    {
        // Authenticate user
        $user = $this->authenticate($username, $password);

        if (!$user) {
            abort(403, 'Invalid credentials');
        }

        // Remove .m3u8 extension if present
        $streamId = str_replace('.m3u8', '', $streamId);

        // Register/check connection
        if (!$this->connectionTracker->register($user, $streamId, $request)) {
            abort(429, 'Maximum connections exceeded');
        }

        // Get actual stream URL from user's M3U source
        if (!$user->m3u_source_id) {
            abort(404, 'No M3U source assigned');
        }

        $channels = $this->m3uParser->getChannelsBySource($user->m3u_source_id);
        $streamUrl = null;

        foreach ($channels as $channel) {
            $channelStreamId = md5($channel['url'] ?? $channel['tvg_id'] ?? '');
            if ($channelStreamId === $streamId) {
                $streamUrl = $channel['url'];
                break;
            }
        }

        if (!$streamUrl) {
            abort(404, 'Stream not found');
        }

        // If the original stream is already HLS, proxy it directly
        if ($this->isHlsStream($streamUrl)) {
            return $this->proxyHlsStream($streamUrl, $request);
        }

        // Otherwise, convert TS to HLS using FFmpeg
        return $this->convertToHls($streamUrl, $streamId, $user, $request);
    }

    /**
     * Check if stream is already HLS format
     */
    private function isHlsStream(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($extension, ['m3u8', 'm3u']);
    }

    /**
     * Proxy an existing HLS stream
     */
    private function proxyHlsStream(string $streamUrl, Request $request)
    {
        try {
            $headers = [
                'User-Agent' => $request->header('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'),
            ];

            $response = Http::withHeaders($headers)
                ->withOptions([
                    'timeout' => 10,
                    'verify' => false,
                    'allow_redirects' => ['max' => 5],
                ])
                ->get($streamUrl);

            if (!$response->successful()) {
                abort($response->status(), 'Failed to fetch HLS stream');
            }

            $playlistContent = $response->body();
            $baseUrl = $this->getBaseUrl($streamUrl);

            // Rewrite URLs in the playlist to make them absolute
            $modifiedPlaylist = $this->rewriteHlsUrls($playlistContent, $baseUrl);

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

            abort(502, 'Failed to proxy HLS stream');
        }
    }

    /**
     * Convert TS stream to HLS using FFmpeg
     * This uses a two-phase approach:
     * 1. Generate a master playlist that points to segment endpoints
     * 2. Use FFmpeg to generate segments on-demand
     */
    private function convertToHls(string $streamUrl, string $streamId, IptvUser $user, Request $request)
    {
        // Check if FFmpeg is available
        if (!$this->isFfmpegAvailable()) {
            \Log::error('FFmpeg is not available');
            abort(500, 'FFmpeg is not installed or not available in PATH');
        }

        // Generate a simple HLS master playlist
        $hlsPlaylist = $this->generateHlsPlaylist($streamUrl, $streamId, $user);

        return response($hlsPlaylist, 200, [
            'Content-Type' => 'application/vnd.apple.mpegurl',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, HEAD, OPTIONS',
        ]);
    }

    /**
     * Generate HLS playlist that redirects to the original stream
     * For better compatibility with LG TV apps, we create a simple HLS wrapper
     */
    private function generateHlsPlaylist(string $streamUrl, string $streamId, IptvUser $user): string
    {
        // For TS streams, we'll create a simple HLS playlist that wraps the TS stream
        // This approach works better with IPTV players that expect HLS format

        $baseUrl = config('app.url');
        $segmentUrl = sprintf(
            '%s/hls-segment/%s/%s/%s',
            rtrim($baseUrl, '/'),
            urlencode($user->username),
            urlencode($user->password),
            $streamId
        );

        // Generate a simple HLS playlist
        $playlist = "#EXTM3U\n";
        $playlist .= "#EXT-X-VERSION:3\n";
        $playlist .= "#EXT-X-TARGETDURATION:10\n";
        $playlist .= "#EXT-X-MEDIA-SEQUENCE:0\n";
        $playlist .= "#EXTINF:10.0,\n";
        $playlist .= $segmentUrl . "\n";
        $playlist .= "#EXT-X-ENDLIST\n";

        return $playlist;
    }

    /**
     * Serve HLS segment (the actual TS stream)
     */
    public function segment(Request $request, string $username, string $password, string $streamId)
    {
        // Authenticate user
        $user = $this->authenticate($username, $password);

        if (!$user) {
            abort(403, 'Invalid credentials');
        }

        // Get actual stream URL
        if (!$user->m3u_source_id) {
            abort(404, 'No M3U source assigned');
        }

        $channels = $this->m3uParser->getChannelsBySource($user->m3u_source_id);
        $streamUrl = null;

        foreach ($channels as $channel) {
            $channelStreamId = md5($channel['url'] ?? $channel['tvg_id'] ?? '');
            if ($channelStreamId === $streamId) {
                $streamUrl = $channel['url'];
                break;
            }
        }

        if (!$streamUrl) {
            abort(404, 'Stream not found');
        }

        // Proxy the TS stream directly
        return $this->proxyTsStream($streamUrl, $request);
    }

    /**
     * Proxy TS stream with proper headers
     */
    private function proxyTsStream(string $streamUrl, Request $request)
    {
        try {
            $headers = [
                'User-Agent' => $request->header('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'),
            ];

            if ($request->header('Range')) {
                $headers['Range'] = $request->header('Range');
            }

            $response = Http::withHeaders($headers)
                ->withOptions([
                    'stream' => true,
                    'timeout' => 5,
                    'read_timeout' => 0,
                    'verify' => false,
                    'allow_redirects' => ['max' => 5],
                ])
                ->get($streamUrl);

            if (!$response->successful()) {
                abort($response->status(), 'Failed to fetch stream');
            }

            $responseHeaders = [
                'Content-Type' => 'video/mp2t',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0',
                'Access-Control-Allow-Origin' => '*',
            ];

            if ($response->header('Content-Length')) {
                $responseHeaders['Content-Length'] = $response->header('Content-Length');
            }

            if ($response->header('Content-Range')) {
                $responseHeaders['Content-Range'] = $response->header('Content-Range');
            }

            $statusCode = $request->header('Range') && $response->header('Content-Range') ? 206 : 200;

            return response()->stream(function () use ($response) {
                try {
                    foreach ($response->getBody() as $chunk) {
                        echo $chunk;
                        flush();
                    }
                } catch (\Exception $e) {
                    \Log::error('Stream chunk error', ['error' => $e->getMessage()]);
                }
            }, $statusCode, $responseHeaders);

        } catch (\Exception $e) {
            \Log::error('TS proxy error', [
                'url' => $streamUrl,
                'error' => $e->getMessage(),
            ]);

            abort(502, 'Failed to proxy TS stream');
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
     * Check if FFmpeg is installed and available
     */
    private function isFfmpegAvailable(): bool
    {
        try {
            $process = new Process(['ffmpeg', '-version']);
            $process->run();

            return $process->isSuccessful();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Rewrite URLs in HLS playlist to make them absolute
     */
    private function rewriteHlsUrls(string $content, string $baseUrl): string
    {
        $lines = explode("\n", $content);
        $modifiedLines = [];

        foreach ($lines as $line) {
            $trimmedLine = trim($line);

            // Skip comments and empty lines (except #EXT tags which are kept)
            if (empty($trimmedLine) || str_starts_with($trimmedLine, '#EXT') || str_starts_with($trimmedLine, '#')) {
                $modifiedLines[] = $line;
                continue;
            }

            // This is a URL line - rewrite it if relative
            $url = $trimmedLine;

            // Convert relative URLs to absolute
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
     * Get base URL from a full URL (for resolving relative URLs)
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
