<?php

namespace App\Http\Controllers;

use App\Models\IptvUser;
use App\Models\ChannelGroup;
use App\Services\M3UGeneratorService;
use App\Services\M3UParserService;
use App\Services\ConnectionTrackerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PlaylistController extends Controller
{
    public function __construct(
        private M3UGeneratorService $m3uGenerator,
        private M3UParserService $m3uParser,
        private ConnectionTrackerService $connectionTracker
    ) {}

    public function getPlaylist(Request $request)
    {
        $username = $request->input('username');
        $password = $request->input('password');

        $user = $this->authenticate($username, $password);

        if (!$user) {
            abort(403, 'Invalid credentials');
        }

        $m3uContent = $this->m3uGenerator->generate($user);

        return response($m3uContent)
            ->header('Content-Type', 'application/x-mpegurl')
            ->header('Content-Disposition', 'attachment; filename="playlist.m3u"');
    }

    public function playerApi(Request $request)
    {
        $username = $request->input('username');
        $password = $request->input('password');
        $action = $request->input('action');

        $user = $this->authenticate($username, $password);

        if (!$user) {
            return response()->json(['error' => 'Invalid credentials'], 403);
        }

        // No action param = return user_info + server_info
        if (!$action) {
            return $this->getUserInfo($user);
        }

        return match ($action) {
            'get_live_categories' => $this->getLiveCategories($user),
            'get_live_streams' => $this->getLiveStreams($user, $request),
            'get_vod_categories' => $this->getVodCategories($user),
            'get_vod_streams' => $this->getVodStreams($user, $request),
            'get_series_categories' => $this->getSeriesCategories($user),
            'get_series' => $this->getSeries($user, $request),
            'get_short_epg' => $this->getShortEpg($user, $request),
            default => response()->json(['error' => 'Unknown action'], 400),
        };
    }

    public function stream(Request $request, string $username, string $password, string $streamId)
    {
        $user = $this->authenticate($username, $password);

        if (!$user) {
            abort(403, 'Invalid credentials');
        }

        // Remove .ts or .m3u8 extension if present
        $streamId = str_replace(['.ts', '.m3u8'], '', $streamId);

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

        // Proxy the stream with request headers
        return $this->proxyStream($streamUrl, $request);
    }

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

    private function getUserInfo(IptvUser $user): \Illuminate\Http\JsonResponse
    {
        $activeConnections = $this->connectionTracker->getActiveCount($user);

        return response()->json([
            'user_info' => [
                'username' => $user->username,
                'password' => $user->password,
                'status' => $user->is_active ? 'Active' : 'Inactive',
                'exp_date' => $user->expires_at ? $user->expires_at->timestamp : null,
                'max_connections' => $user->max_connections,
                'active_cons' => $activeConnections,
                'created_at' => $user->created_at->timestamp,
                'is_trial' => 0,
            ],
            'server_info' => [
                'url' => parse_url(config('app.url'), PHP_URL_HOST) ?? 'localhost',
                'port' => '80',
                'https_port' => '443',
                'server_protocol' => 'http',
                'rtmp_port' => '1935',
                'timezone' => config('app.timezone', 'UTC'),
                'timestamp_now' => now()->timestamp,
                'time_now' => now()->format('Y-m-d H:i:s'),
            ],
        ]);
    }

    private function getLiveCategories(IptvUser $user): \Illuminate\Http\JsonResponse
    {
        $groups = [];

        // Get groups from user's M3U source
        if (!$user->m3u_source_id) {
            return response()->json($groups);
        }

        $channels = $this->m3uParser->getChannelsBySource($user->m3u_source_id);
        $groupNames = [];
        $groupCounts = [];

        // Extract unique group names from channels and count streams per category
        foreach ($channels as $channel) {
            $groupTitle = $channel['group_title'] ?? 'Uncategorized';

            if (!in_array($groupTitle, $groupNames)) {
                $groupNames[] = $groupTitle;
                $groupCounts[$groupTitle] = 0;
            }
            $groupCounts[$groupTitle]++;
        }

        sort($groupNames);

        foreach ($groupNames as $groupName) {
            $groups[] = [
                'category_id' => $this->getCategoryId($groupName),
                'category_name' => $groupName,
                'parent_id' => 0,
            ];
        }

        return response()->json($groups);
    }

    /**
     * Generate consistent category ID from group name using hash
     * This ensures the same category always gets the same ID
     */
    private function getCategoryId(string $groupName): string
    {
        // Use CRC32 to generate a numeric ID from group name
        // This keeps category IDs consistent across requests
        return (string) abs(crc32($groupName));
    }

    private function getLiveStreams(IptvUser $user, Request $request): \Illuminate\Http\JsonResponse
    {
        $categoryId = $request->input('category_id');
        $streams = [];

        // Get channels from user's M3U source
        if (!$user->m3u_source_id) {
            return response()->json($streams);
        }

        $channels = $this->m3uParser->getChannelsBySource($user->m3u_source_id);

        // Filter channels by category if category_id is provided
        foreach ($channels as $channel) {
            $groupTitle = $channel['group_title'] ?? 'Uncategorized';
            $channelCategoryId = $this->getCategoryId($groupTitle);

            // Skip this channel if it doesn't match the requested category
            if ($categoryId && $channelCategoryId !== $categoryId) {
                continue;
            }

            $streamId = md5($channel['url'] ?? $channel['tvg_id'] ?? rand());

            $streams[] = [
                'num' => count($streams) + 1,
                'name' => $channel['name'] ?? 'Unknown',
                'stream_type' => 'live',
                'stream_id' => $streamId,
                'stream_icon' => $channel['tvg_logo'] ?? '',
                'epg_channel_id' => $channel['tvg_id'] ?? '',
                'added' => now()->timestamp,
                'category_id' => $channelCategoryId,
                'custom_sid' => '',
                'tv_archive' => 0,
                'direct_source' => '',
                'tv_archive_duration' => 0,
            ];
        }

        return response()->json($streams);
    }

    private function proxyStream(string $streamUrl, Request $request): StreamedResponse
    {
        // Prepare headers to forward to upstream server
        $headers = [];

        // Forward User-Agent (important for some IPTV providers)
        if ($request->header('User-Agent')) {
            $headers['User-Agent'] = $request->header('User-Agent');
        } else {
            // Default User-Agent for IPTV players
            $headers['User-Agent'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36';
        }

        // Forward Range header (for seeking/resuming streams)
        if ($request->header('Range')) {
            $headers['Range'] = $request->header('Range');
        }

        // Forward Referer if present
        if ($request->header('Referer')) {
            $headers['Referer'] = $request->header('Referer');
        }

        // Detect content type from URL
        $contentType = $this->detectContentType($streamUrl);

        // For HLS playlists, use special handling
        if ($this->isHlsPlaylist($streamUrl)) {
            return $this->proxyHlsPlaylist($streamUrl, $headers, $request);
        }

        try {
            // Make the upstream request
            $response = Http::withHeaders($headers)
                ->withOptions([
                    'stream' => true,
                    'timeout' => 30,          // Initial connection timeout (increased from 5s)
                    'read_timeout' => 0,      // No timeout for reading stream chunks
                    'verify' => false,        // Disable SSL verification (some IPTV sources use self-signed certs)
                    'allow_redirects' => [
                        'max' => 5,
                        'strict' => true,
                        'referer' => true,
                        'track_redirects' => true,
                    ],
                ])
                ->get($streamUrl);

            // If upstream request failed, log and abort
            if (!$response->successful()) {
                \Log::error('Stream proxy failed', [
                    'url' => $streamUrl,
                    'status' => $response->status(),
                    'error' => $response->body(),
                ]);

                abort($response->status(), 'Upstream stream error');
            }

            // Get response headers from upstream
            $responseHeaders = [
                'Content-Type' => $response->header('Content-Type') ?? $contentType,
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0',
                'Access-Control-Allow-Origin' => '*',
                'Access-Control-Allow-Methods' => 'GET, HEAD, OPTIONS',
                'Access-Control-Allow-Headers' => 'Range, User-Agent',
            ];

            // Forward Content-Length if present
            if ($response->header('Content-Length')) {
                $responseHeaders['Content-Length'] = $response->header('Content-Length');
            }

            // Forward Content-Range if present (for Range requests)
            if ($response->header('Content-Range')) {
                $responseHeaders['Content-Range'] = $response->header('Content-Range');
            }

            // Determine status code (206 for partial content, 200 otherwise)
            $statusCode = $request->header('Range') && $response->header('Content-Range') ? 206 : 200;

            // Stream the response
            return response()->stream(function () use ($response) {
                try {
                    foreach ($response->getBody() as $chunk) {
                        echo $chunk;
                        flush();
                    }
                } catch (\Exception $e) {
                    \Log::error('Stream chunk error', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }, $statusCode, $responseHeaders);

        } catch (\Exception $e) {
            \Log::error('Stream proxy exception', [
                'url' => $streamUrl,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            abort(502, 'Failed to connect to upstream server');
        }
    }

    /**
     * Detect content type from stream URL
     */
    private function detectContentType(string $url): string
    {
        $extension = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

        return match ($extension) {
            'm3u8', 'm3u' => 'application/vnd.apple.mpegurl',
            'ts' => 'video/mp2t',
            'mp4' => 'video/mp4',
            'mkv' => 'video/x-matroska',
            'avi' => 'video/x-msvideo',
            'flv' => 'video/x-flv',
            default => 'video/mp2t', // Default to MPEG-TS for live streams
        };
    }

    private function getVodCategories(IptvUser $user): \Illuminate\Http\JsonResponse
    {
        // Currently returns empty array - VOD support can be added later
        return response()->json([]);
    }

    private function getVodStreams(IptvUser $user, Request $request): \Illuminate\Http\JsonResponse
    {
        // Currently returns empty array - VOD support can be added later
        return response()->json([]);
    }

    private function getSeriesCategories(IptvUser $user): \Illuminate\Http\JsonResponse
    {
        // Currently returns empty array - Series support can be added later
        return response()->json([]);
    }

    private function getSeries(IptvUser $user, Request $request): \Illuminate\Http\JsonResponse
    {
        // Currently returns empty array - Series support can be added later
        return response()->json([]);
    }

    private function getShortEpg(IptvUser $user, Request $request): \Illuminate\Http\JsonResponse
    {
        $streamId = $request->input('stream_id');
        $limit = $request->input('limit', 100);

        // Currently returns empty array - EPG support can be added later
        // This would typically return EPG data from external EPG source
        return response()->json([
            'epg_listings' => [],
        ]);
    }

    /**
     * Check if URL is an HLS playlist
     */
    private function isHlsPlaylist(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($extension, ['m3u8', 'm3u']);
    }

    /**
     * Proxy HLS playlist with URL rewriting for chunk URLs
     * This ensures all segments go through our proxy for maximum compatibility
     */
    private function proxyHlsPlaylist(string $playlistUrl, array $headers, Request $request)
    {
        try {
            // Fetch the HLS playlist from upstream
            $response = Http::withHeaders($headers)
                ->withOptions([
                    'timeout' => 30,  // Increased from 10s to 30s
                    'verify' => false,
                    'allow_redirects' => [
                        'max' => 5,
                        'strict' => true,
                    ],
                ])
                ->get($playlistUrl);

            if (!$response->successful()) {
                \Log::error('HLS playlist fetch failed', [
                    'url' => $playlistUrl,
                    'status' => $response->status(),
                ]);

                abort($response->status(), 'Failed to fetch HLS playlist');
            }

            $playlistContent = $response->body();
            $baseUrl = $this->getBaseUrl($playlistUrl);

            // Parse and rewrite the playlist
            $modifiedPlaylist = $this->rewriteHlsUrls($playlistContent, $baseUrl, $playlistUrl);

            return response($modifiedPlaylist, 200, [
                'Content-Type' => 'application/vnd.apple.mpegurl',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0',
                'Access-Control-Allow-Origin' => '*',
            ]);

        } catch (\Exception $e) {
            \Log::error('HLS playlist proxy error', [
                'url' => $playlistUrl,
                'error' => $e->getMessage(),
            ]);

            abort(502, 'Failed to proxy HLS playlist');
        }
    }

    /**
     * Rewrite URLs in HLS playlist to go through our proxy
     */
    private function rewriteHlsUrls(string $content, string $baseUrl, string $originalUrl): string
    {
        $lines = explode("\n", $content);
        $modifiedLines = [];

        foreach ($lines as $line) {
            $trimmedLine = trim($line);

            // Skip comments and empty lines (except #EXT tags which are kept)
            if (empty($trimmedLine) || str_starts_with($trimmedLine, '#EXT')) {
                $modifiedLines[] = $line;
                continue;
            }

            // If it's a comment, keep it as is
            if (str_starts_with($trimmedLine, '#')) {
                $modifiedLines[] = $line;
                continue;
            }

            // This is a URL line - rewrite it
            $url = $trimmedLine;

            // Convert relative URLs to absolute
            if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
                // Relative URL
                if (str_starts_with($url, '/')) {
                    // Absolute path - use scheme and host from base URL
                    $parsedBase = parse_url($baseUrl);
                    $url = $parsedBase['scheme'] . '://' . $parsedBase['host'] . $url;
                } else {
                    // Relative path - append to base URL
                    $url = rtrim($baseUrl, '/') . '/' . $url;
                }
            }

            // For now, keep URLs as-is (direct)
            // In a full proxy implementation, you'd want to rewrite these through your proxy
            // Example: $url = $this->generateProxyUrl($url, $user);
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
