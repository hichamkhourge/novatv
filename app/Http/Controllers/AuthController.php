<?php

namespace App\Http\Controllers;

use App\Models\IptvUser;
use App\Services\M3UParserService;
use App\Services\ConnectionTrackerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    public function __construct(
        private M3UParserService $m3uParser,
        private ConnectionTrackerService $connectionTracker
    ) {}

    /**
     * Nginx auth_request endpoint
     *
     * This endpoint is called by nginx via auth_request directive before proxying streams.
     * It validates user credentials, checks connection limits, and returns the upstream URL.
     *
     * Headers from nginx:
     * - X-Stream-Username: User's username
     * - X-Stream-Password: User's password
     * - X-Stream-Id: The stream ID (MD5 hash)
     *
     * Returns:
     * - 200 OK with X-Upstream-URL header on success
     * - 403 Forbidden if credentials invalid
     * - 404 Not Found if stream doesn't exist
     * - 429 Too Many Requests if connection limit exceeded
     */
    public function authenticateStream(Request $request)
    {
        $username = $request->header('X-Stream-Username');
        $password = $request->header('X-Stream-Password');
        $streamId = $request->header('X-Stream-Id');

        Log::debug('Stream auth request', [
            'username' => $username,
            'stream_id' => $streamId,
            'ip' => $request->ip(),
        ]);

        // Validate required parameters
        if (!$username || !$password || !$streamId) {
            Log::warning('Stream auth: Missing parameters');
            return response('Missing parameters', 400);
        }

        // Authenticate user
        $user = $this->authenticate($username, $password);

        if (!$user) {
            Log::warning('Stream auth: Invalid credentials', [
                'username' => $username,
                'ip' => $request->ip(),
            ]);
            return response('Unauthorized', 403);
        }

        // Check connection limit
        if (!$this->connectionTracker->register($user, $streamId, $request)) {
            Log::warning('Stream auth: Max connections exceeded', [
                'username' => $username,
                'stream_id' => $streamId,
                'max_connections' => $user->max_connections,
            ]);
            return response('Max connections exceeded', 429);
        }

        // Resolve upstream URL
        $upstreamUrl = $this->resolveStreamUrl($user, $streamId);

        if (!$upstreamUrl) {
            Log::warning('Stream auth: Stream not found', [
                'username' => $username,
                'stream_id' => $streamId,
            ]);
            return response('Stream not found', 404);
        }

        // Follow redirects to get final URL
        // Many IPTV providers use 302 redirects to CDN/load balancers
        $finalUrl = $this->followRedirects($upstreamUrl);

        if (!$finalUrl) {
            Log::error('Stream auth: Failed to resolve redirects', [
                'username' => $username,
                'stream_id' => $streamId,
                'upstream' => $upstreamUrl,
            ]);
            return response('Failed to resolve stream URL', 500);
        }

        // Parse final URL into components for nginx proxy_pass
        $urlComponents = $this->parseUpstreamUrl($finalUrl);

        if (!$urlComponents) {
            Log::error('Stream auth: Invalid upstream URL', [
                'username' => $username,
                'stream_id' => $streamId,
                'upstream' => $upstreamUrl,
            ]);
            return response('Invalid upstream URL', 500);
        }

        Log::info('Stream auth: Success', [
            'username' => $username,
            'stream_id' => $streamId,
            'upstream_original' => $upstreamUrl,
            'upstream_final' => $finalUrl,
            'redirected' => $upstreamUrl !== $finalUrl,
            'components' => $urlComponents,
        ]);

        // Return success with upstream URL components as separate headers
        // Nginx will reconstruct the full URL using these components
        return response('OK', 200)
            ->header('X-Upstream-Scheme', $urlComponents['scheme'])
            ->header('X-Upstream-Host', $urlComponents['host'])
            ->header('X-Upstream-Port', (string) $urlComponents['port'])
            ->header('X-Upstream-Path', $urlComponents['path'])
            ->header('X-User-Id', (string) $user->id)
            ->header('X-Max-Connections', (string) $user->max_connections);
    }

    /**
     * Authenticate user credentials
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
     * Resolve stream ID to upstream URL
     * Uses caching for performance (60 second TTL)
     */
    private function resolveStreamUrl(IptvUser $user, string $streamId): ?string
    {
        // Cache key includes user ID and stream ID
        $cacheKey = "stream_url:{$user->id}:{$streamId}";

        return Cache::remember($cacheKey, 60, function () use ($user, $streamId) {
            if (!$user->m3u_source_id) {
                Log::debug('No M3U source assigned to user', ['user_id' => $user->id]);
                return null;
            }

            // Get channels from M3U source (this is also cached by M3UParserService)
            $channels = $this->m3uParser->getChannelsBySource($user->m3u_source_id);

            // Find channel matching stream ID
            foreach ($channels as $channel) {
                $channelStreamId = md5($channel['url'] ?? $channel['tvg_id'] ?? '');

                if ($channelStreamId === $streamId) {
                    return $channel['url'];
                }
            }

            Log::debug('Stream ID not found in channels', [
                'user_id' => $user->id,
                'stream_id' => $streamId,
                'channel_count' => count($channels),
            ]);

            return null;
        });
    }

    /**
     * Parse upstream URL into components for nginx proxy_pass
     *
     * Returns array with: scheme, host, port, path
     * Example: http://example.com:8080/path/file.m3u8
     * Returns: ['scheme' => 'http', 'host' => 'example.com', 'port' => 8080, 'path' => '/path/file.m3u8']
     */
    private function parseUpstreamUrl(string $url): ?array
    {
        $parsed = parse_url($url);

        if (!$parsed || !isset($parsed['scheme']) || !isset($parsed['host'])) {
            return null;
        }

        // Determine port (use default if not specified)
        $port = $parsed['port'] ?? ($parsed['scheme'] === 'https' ? 443 : 80);

        // Construct path with query string if present
        $path = $parsed['path'] ?? '/';
        if (isset($parsed['query'])) {
            $path .= '?' . $parsed['query'];
        }

        return [
            'scheme' => $parsed['scheme'],
            'host' => $parsed['host'],
            'port' => $port,
            'path' => $path,
        ];
    }

    /**
     * Follow HTTP redirects to get the final URL
     *
     * Many IPTV providers use 302/301 redirects to CDN or load balancers.
     * This method follows up to 5 redirects and returns the final direct URL.
     *
     * @param string $url Initial URL
     * @return string|null Final URL after following redirects, or null on failure
     */
    private function followRedirects(string $url): ?string
    {
        $maxRedirects = 5;
        $redirectCount = 0;
        $currentUrl = $url;

        try {
            while ($redirectCount < $maxRedirects) {
                // Make a GET request to check for redirects
                // Note: Using GET instead of HEAD because some IPTV servers don't respond to HEAD properly
                $response = \Illuminate\Support\Facades\Http::withOptions([
                    'allow_redirects' => false,  // Don't follow automatically
                    'timeout' => 5,
                    'verify' => false,  // Disable SSL verification for self-signed certs
                ])
                ->get($currentUrl);

                $statusCode = $response->status();

                // If it's a redirect (301, 302, 303, 307, 308)
                if (in_array($statusCode, [301, 302, 303, 307, 308])) {
                    $location = $response->header('Location');

                    if (!$location) {
                        Log::warning('Redirect without Location header', [
                            'url' => $currentUrl,
                            'status' => $statusCode,
                        ]);
                        break;
                    }

                    // Handle relative redirects
                    if (!str_starts_with($location, 'http://') && !str_starts_with($location, 'https://')) {
                        // Relative URL - construct absolute URL
                        $parsed = parse_url($currentUrl);
                        $base = $parsed['scheme'] . '://' . $parsed['host'];
                        if (isset($parsed['port'])) {
                            $base .= ':' . $parsed['port'];
                        }

                        if (str_starts_with($location, '/')) {
                            // Absolute path
                            $location = $base . $location;
                        } else {
                            // Relative path
                            $dir = dirname($parsed['path'] ?? '/');
                            $location = $base . $dir . '/' . $location;
                        }
                    }

                    Log::debug('Following redirect', [
                        'from' => $currentUrl,
                        'to' => $location,
                        'status' => $statusCode,
                        'redirect_number' => $redirectCount + 1,
                    ]);

                    $currentUrl = $location;
                    $redirectCount++;
                } elseif ($statusCode >= 200 && $statusCode < 300) {
                    // Success - this is the final URL
                    Log::debug('Final URL resolved', [
                        'original' => $url,
                        'final' => $currentUrl,
                        'redirect_count' => $redirectCount,
                    ]);
                    return $currentUrl;
                } else {
                    // Error status (4xx, 5xx)
                    Log::warning('Upstream returned error status', [
                        'url' => $currentUrl,
                        'status' => $statusCode,
                    ]);
                    // Return the current URL anyway - let nginx handle the error
                    return $currentUrl;
                }
            }

            if ($redirectCount >= $maxRedirects) {
                Log::warning('Too many redirects', [
                    'url' => $url,
                    'redirect_count' => $redirectCount,
                    'final_url' => $currentUrl,
                ]);
            }

            // Return the last URL we got
            return $currentUrl;

        } catch (\Exception $e) {
            Log::error('Failed to follow redirects', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            // On error, return the original URL - let nginx try it
            return $url;
        }
    }
}
