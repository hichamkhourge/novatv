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

        Log::info('Stream auth: Success', [
            'username' => $username,
            'stream_id' => $streamId,
            'upstream' => $upstreamUrl,
        ]);

        // Return success with upstream URL in header (nginx will use this for proxy_pass)
        return response('OK', 200)
            ->header('X-Upstream-URL', $upstreamUrl)
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
}
