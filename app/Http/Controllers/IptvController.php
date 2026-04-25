<?php

namespace App\Http\Controllers;

use App\Models\AccessLog;
use App\Models\Channel;
use App\Models\ChannelGroup;
use App\Models\IptvAccount;
use App\Models\StreamSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Handles all IPTV client-facing endpoints:
 *  - M3U playlist (/get.php)
 *  - Xtream Codes API (/player_api.php)
 *  - Nginx auth_request (/api/auth/stream)
 *  - Stream proxy fallback (/live/{username}/{password}/{id}.ts|m3u8)
 *
 * Channels are always scoped to the account's linked M3U source.
 */
class IptvController extends Controller
{
    // -------------------------------------------------------------------------
    // Nginx auth_request endpoint
    // -------------------------------------------------------------------------

    /**
     * Called INTERNALLY by Nginx auth_request before proxy_pass-ing a stream.
     *
     * Nginx passes stream info via FastCGI headers:
     *   HTTP_X_STREAM_USERNAME, HTTP_X_STREAM_PASSWORD, HTTP_X_STREAM_ID
     *
     * Returns:
     *   200 + X-Upstream-* headers  → Nginx proxies the stream to the upstream
     *   401                         → Nginx returns 401 to the client
     *   403                         → Nginx returns 403 (suspended/expired/max conn)
     *   404                         → Nginx returns 404 (channel not found)
     */
    public function authStream(Request $request): Response
    {
        // Credentials are passed by nginx as query params in the auth_request URI:
        //   auth_request /api/auth/stream?u=$stream_username&p=$stream_password&id=$stream_id
        // The map directives in maps.conf extract them from the parent request URI.
        $username = $request->query('u');
        $password = $request->query('p');
        $streamId = $request->query('id');

        // ── 1. Authenticate ───────────────────────────────────────────────────
        $account = IptvAccount::where('username', $username)
            ->where('password', $password)
            ->first();

        if (! $account) {
            return response('Unauthorized', 401);
        }

        if ($account->status === 'suspended') {
            return response('Suspended', 403);
        }

        if ($account->isExpired()) {
            return response('Expired', 403);
        }

        // ── 2. Find channel ───────────────────────────────────────────────────
        $channelId = (int) preg_replace('/\.\w+$/', '', (string) $streamId);

        $channel = $this->accountChannels($account)
            ->where('channels.id', $channelId)
            ->first();

        if (! $channel || ! $channel->is_active) {
            return response('Channel not found', 404);
        }

        // ── 3. Enforce max_connections ────────────────────────────────────────
        $ip             = $request->ip();
        $activeSessions = StreamSession::where('account_id', $account->id)
            ->where('last_seen_at', '>', now()->subSeconds(30))
            ->where(fn ($q) => $q->where('channel_id', '!=', $channelId)->orWhere('ip_address', '!=', $ip))
            ->count();

        if ($activeSessions >= $account->max_connections) {
            return response('Max connections', 429);
        }

        // ── 4. Register session ───────────────────────────────────────────────
        StreamSession::updateOrCreate(
            ['account_id' => $account->id, 'channel_id' => $channelId, 'ip_address' => $ip],
            ['started_at' => now(), 'last_seen_at' => now()],
        );

        // ── 5. Resolve provider URL (follow any 302 redirects) ────────────────
        // Resolve to the final CDN URL so Nginx proxy_pass goes directly there.
        $providerUrl = $channel->stream_url;
        $finalUrl    = $this->resolveStreamUrl($providerUrl);

        // ── 6. Return upstream URL for Nginx proxy_pass ───────────────────────
        // Nginx reads this via: auth_request_set $upstream_url $upstream_http_x_upstream_url;
        return response('OK', 200, [
            'X-Upstream-URL' => $finalUrl,
            'Content-Type'   => 'text/plain',
        ]);
    }

    /**
     * Resolve a provider URL by following HTTP redirects.
     * Uses a short timeout to prevent waiting too long if the stream doesn't redirect.
     */
    private function resolveStreamUrl(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => config('iptv.stream.redirect_timeout', 5),
            CURLOPT_CONNECTTIMEOUT => config('iptv.stream.redirect_connect_timeout', 3),
            CURLOPT_USERAGENT      => request()->header('User-Agent') ?? 'VLC/3.0.20 LibVLC/3.0.20',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
            CURLOPT_RANGE          => '0-0', // Try to only fetch first byte to speed up CDNs
        ]);

        $startTime = microtime(true);
        curl_exec($ch);
        $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $redirectCount = curl_getinfo($ch, CURLINFO_REDIRECT_COUNT);
        curl_close($ch);

        // Log connection diagnostics
        if (config('iptv.logging.connection_diagnostics', false)) {
            \Log::channel(config('iptv.logging.channel', 'stack'))->info('Stream URL resolution', [
                'original_url' => $url,
                'final_url' => $finalUrl,
                'http_code' => $httpCode,
                'redirect_count' => $redirectCount,
                'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
            ]);
        }

        return ($finalUrl && $finalUrl !== '') ? $finalUrl : $url;
    }

    /**
     * Detect provider-specific configuration based on URL and credentials.
     *
     * This enables special handling for providers with unique requirements:
     * - ZAZY: Requires cookie persistence across redirects for load balancing
     * - Future providers can be added here
     */
    private function detectProviderConfig(string $upstreamUrl, string $username, string $password): array
    {
        $defaultConfig = [
            'provider_name' => 'generic',
            'connection_timeout' => config('iptv.stream.connection_timeout', 15),
            'max_redirects' => 5,
            'use_cookies' => true, // Default: always use cookies for safety
        ];

        // Detect ZAZY provider
        $zazyPatterns = config('iptv.providers.zazy.detection_patterns', ['172.110.220.61', 'zazy']);
        $isZazy = false;

        foreach ($zazyPatterns as $pattern) {
            if (stripos($upstreamUrl, $pattern) !== false ||
                stripos($username, $pattern) !== false ||
                stripos($password, $pattern) !== false) {
                $isZazy = true;
                break;
            }
        }

        if ($isZazy) {
            $fixMode = config('iptv.providers.zazy.fix_mode', 'conservative');
            $modeConfig = config("iptv.providers.zazy.{$fixMode}", []);

            return [
                'provider_name' => 'zazy',
                'fix_mode' => $fixMode,
                'connection_timeout' => $modeConfig['connection_timeout'] ?? 20,
                'max_redirects' => $modeConfig['max_redirects'] ?? 10,
                'use_cookies' => $modeConfig['use_persistent_cookies'] ?? true,
            ];
        }

        return $defaultConfig;
    }

    // -------------------------------------------------------------------------
    // M3U Playlist
    // -------------------------------------------------------------------------

    /**
     * Generate M3U playlist for the authenticated account.
     *
     * Stream URLs use our proxy with a .ts extension. ExoPlayer (IPTV Smarters)
     * uses the .ts extension to identify MPEGTS and skips the 20-second HLS probe
     * that causes the "channel will be back soon" error.
     * Our streamProxy strips the extension before redirecting to the provider.
     *
     * GET /get.php?username=X&password=Y&type=m3u_plus
     */
    public function getPlaylist(Request $request): StreamedResponse|Response
    {
        /** @var IptvAccount $account */
        $account = $request->attributes->get('iptv_account');

        $this->logAccess($request, $account, 'playlist', 'ok');

        try {
            $channels = $this->accountChannels($account)
                ->with('channelGroup')
                ->get();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('getPlaylist: DB error', [
                'account' => $account->username,
                'error'   => $e->getMessage(),
            ]);
            return response('Server error: ' . $e->getMessage(), 500, ['Content-Type' => 'text/plain']);
        }

        $baseUrl  = rtrim(config('app.url'), '/');
        $username = $account->username;
        $password = $account->password;

        return response()->stream(function () use ($channels, $baseUrl, $username, $password) {
            echo "#EXTM3U\r\n";

            foreach ($channels as $channel) {
                // .ts extension tells ExoPlayer this is MPEGTS → no HLS probe → instant start
                // streamProxy strips the extension and redirects to the correct provider URL
                $streamUrl = "{$baseUrl}/live/{$username}/{$password}/{$channel->id}.ts";

                echo sprintf(
                    "#EXTINF:-1 tvg-id=\"%s\" tvg-name=\"%s\" tvg-logo=\"%s\" group-title=\"%s\",%s\r\n%s\r\n",
                    $channel->tvg_id ?? '',
                    $channel->tvg_name ?? $channel->name,
                    $channel->logo_url ?? '',
                    $channel->channelGroup?->name ?? 'Uncategorized',
                    $channel->name,
                    $streamUrl,
                );
            }
        }, 200, [
            'Content-Type'        => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="playlist.m3u"',
            'Cache-Control'       => 'no-cache, no-store',
        ]);
    }



    // -------------------------------------------------------------------------
    // Xtream Codes API
    // -------------------------------------------------------------------------

    /**
     * Main Xtream Codes API entry point.
     *
     * GET|POST /player_api.php?username=X&password=Y&action=...
     */
    public function playerApi(Request $request): JsonResponse
    {
        /** @var IptvAccount $account */
        $account = $request->attributes->get('iptv_account');
        $action  = $request->input('action', 'login');

        $this->logAccess($request, $account, $action ?: 'login', 'ok');

        return match ($action) {
            'get_live_categories'   => $this->getLiveCategories($account),
            'get_live_streams'      => $this->getLiveStreams($account, $request),
            'get_vod_categories'    => response()->json([]),
            'get_vod_streams'       => response()->json([]),
            'get_series_categories' => response()->json([]),
            'get_series'            => response()->json([]),
            'get_short_epg'         => response()->json([]),
            default                 => $this->getAccountInfo($account, $request),
        };
    }

    /**
     * Return account + server info (login action).
     */
    private function getAccountInfo(IptvAccount $account, Request $request): JsonResponse
    {
        $host   = $request->getHost();
        $scheme = $request->getScheme();

        return response()->json([
            'user_info' => [
                'username'               => $account->username,
                'password'               => $account->password,
                'status'                 => $account->status,
                'exp_date'               => $account->expires_at?->timestamp,
                'is_trial'               => '0',
                'active_cons'            => (string) $account->streamSessions()
                    ->where('last_seen_at', '>', now()->subSeconds(30))
                    ->count(),
                'max_connections'        => (string) $account->max_connections,
                'allowed_output_formats' => ['ts', 'm3u8'],
            ],
            'server_info' => [
                'url'             => $host,
                'port'            => '80',
                'https_port'      => '443',
                'server_protocol' => $scheme,
                'rtmp_port'       => '1935',
                'timezone'        => config('app.timezone'),
                'timestamp_now'   => time(),
                'time_now'        => now()->toDateTimeString(),
            ],
        ]);
    }

    /**
     * Return live stream categories (channel groups) for this account's source.
     * Queries ChannelGroup directly via a subquery — no need to load all channels.
     */
    private function getLiveCategories(IptvAccount $account): JsonResponse
    {
        // Get the source_id from the account
        $sourceId = null;
        try { $sourceId = $account->m3u_source_id; } catch (\Throwable) {}

        if (! $sourceId) {
            return response()->json([]);
        }

        // Find all distinct channel_group_ids used by this source's channels
        $groupIds = Channel::where('m3u_source_id', $sourceId)
            ->where('is_active', true)
            ->distinct()
            ->pluck('channel_group_id');

        $groups = ChannelGroup::whereIn('id', $groupIds)
            ->orderBy('name')
            ->get()
            ->map(fn (ChannelGroup $g) => [
                'category_id'   => (string) $g->id,
                'category_name' => $g->name,
                'parent_id'     => 0,
            ])
            ->values();

        return response()->json($groups);
    }

    /**
     * Return live streams scoped to the account's M3U source.
     * Optionally filtered by category_id (= channel_group_id in our DB).
     */
    private function getLiveStreams(IptvAccount $account, Request $request): JsonResponse
    {
        $categoryId = $request->input('category_id');

        $query = $this->accountChannels($account);

        if ($categoryId) {
            $query->where('channel_group_id', (int) $categoryId);
        }

        $streams = $query->get()->values()->map(fn (Channel $ch, int $i) => [
            'num'                 => $i + 1,
            'name'                => $ch->name,
            'stream_type'         => 'live',
            'stream_id'           => $ch->id,
            'stream_icon'         => $ch->logo_url ?? '',
            'epg_channel_id'      => $ch->tvg_id ?? '',
            'added'               => (string) ($ch->created_at?->timestamp ?? 0),
            'category_id'         => (string) ($ch->channel_group_id ?? ''),
            'custom_sid'          => '',
            'tv_archive'          => 0,
            'tv_archive_duration' => 0,
            'direct_source'       => '',
            'thumbnail'           => $ch->logo_url ?? '',
        ]);

        return response()->json($streams);
    }

    /**
     * Authenticate, validate the channel, then stream the content directly.
     *
     * Streaming through PHP (instead of a 302 redirect) is required because
     * ExoPlayer (IPTV Smarters) uses the Content-Type header to identify MPEGTS.
     * A redirect loses this header — ExoPlayer probes for HLS and waits 20s.
     *
     * NOTE: This requires Cloudflare proxy to be DISABLED for /live/* routes.
     * In Cloudflare → Rules → Page Rules: novatv.novadevlabs.com/live/*
     *   → Cache Level: Bypass, Disable Apps, Response Buffering: Off
     * OR: use the server's direct IP for stream URLs to bypass Cloudflare entirely.
     *
     * GET /live/{username}/{password}/{channel_id}.ts
     */
    public function streamProxy(
        Request $request,
        string $username,
        string $password,
        string $streamId
    ): \Illuminate\Http\Response|\Symfony\Component\HttpFoundation\StreamedResponse {
        // ── 1. Authenticate ──────────────────────────────────────────────────
        $account = IptvAccount::where('username', $username)
            ->where('password', $password)
            ->first();

        if (! $account || ! $account->isActive()) {
            return response('Unauthorized', 401, ['Content-Type' => 'text/plain']);
        }

        // ── 2. Find channel ──────────────────────────────────────────────────
        $channelId = (int) preg_replace('/\.\w+$/', '', $streamId);

        $channel = $this->accountChannels($account)
            ->where('channels.id', $channelId)
            ->first();

        if (! $channel || ! $channel->is_active) {
            return response('Channel not found', 404, ['Content-Type' => 'text/plain']);
        }

        // ── 3. Connection limit ──────────────────────────────────────────────
        $ip             = $request->ip();
        $activeSessions = StreamSession::where('account_id', $account->id)
            ->where('last_seen_at', '>', now()->subSeconds(30))
            ->where(fn ($q) => $q->where('channel_id', '!=', $channelId)->orWhere('ip_address', '!=', $ip))
            ->count();

        if ($activeSessions >= $account->max_connections) {
            return response('Max connections reached', 403, ['Content-Type' => 'text/plain']);
        }

        // ── 4. Register session ──────────────────────────────────────────────
        $session = StreamSession::updateOrCreate(
            ['account_id' => $account->id, 'channel_id' => $channelId, 'ip_address' => $ip],
            ['started_at' => now(), 'last_seen_at' => now()],
        );

        // ── 5. Stream proxy ──────────────────────────────────────────────────
        // Strategy: connect to provider, validate the first chunk is real MPEGTS
        // (>= 188 bytes), then send HTTP headers + stream data immediately.
        // This gives near-instant playback — no waiting for a large pre-buffer.
        $upstreamUrl    = $channel->stream_url;
        $userAgent      = 'VLC/3.0.20 LibVLC/3.0.20';
        $firstChunk     = '';       // Accumulate only until first write callback
        $headersSent    = false;    // Have we committed the HTTP response yet?

        // ── Provider-specific configuration ──────────────────────────────────
        $providerConfig = $this->detectProviderConfig($upstreamUrl, $username, $password);

        if (config('iptv.logging.provider_handling', false)) {
            \Log::info('Stream proxy provider config', [
                'provider'           => $providerConfig['provider_name'],
                'upstream_url'       => $upstreamUrl,
                'connection_timeout' => $providerConfig['connection_timeout'],
                'username'           => $username,
            ]);
        }

        // ── Phase 1: connect and grab the first data chunk ───────────────────
        // We run curl just long enough to receive the first chunk from the provider.
        // This lets us validate it's real MPEGTS before committing any response headers.
        $ch = curl_init($upstreamUrl);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => $providerConfig['max_redirects'],
            CURLOPT_TIMEOUT        => 3600,
            CURLOPT_CONNECTTIMEOUT => $providerConfig['connection_timeout'],
            CURLOPT_USERAGENT      => $userAgent,
            CURLOPT_BUFFERSIZE     => 65536,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_COOKIEFILE     => $providerConfig['use_cookies'] ? '' : null,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_WRITEFUNCTION  => function ($curl, $data) use (&$firstChunk, &$headersSent): int {
                if (! $headersSent) {
                    // Still in validation phase — accumulate
                    $firstChunk .= $data;
                } else {
                    // Headers already sent — pipe directly to client
                    echo $data;
                    if (ob_get_level()) ob_flush();
                    flush();
                }
                return strlen($data);
            },
            CURLOPT_HEADERFUNCTION => static fn ($curl, $header): int => strlen($header),
        ]);

        $mh          = curl_multi_init();
        curl_multi_add_handle($mh, $ch);

        $active      = 1;
        $waitStart   = microtime(true);
        $maxWait     = (float) config('iptv.stream.connection_timeout', 15);

        // Run until we have at least one chunk OR the provider closes/times out
        do {
            curl_multi_exec($mh, $active);
            curl_multi_select($mh, 0.05);
            if (strlen($firstChunk) > 0) break;  // Got first data — stop waiting
        } while ($active && (microtime(true) - $waitStart) < $maxWait);

        // ── Phase 2: validate first chunk ────────────────────────────────────
        // Anything under 188 bytes = provider returned an error page, not MPEGTS.
        if (strlen($firstChunk) < 188) {
            curl_multi_remove_handle($mh, $ch);
            curl_multi_close($mh);
            curl_close($ch);
            $session->delete();

            \Log::error('Stream upstream failed', [
                'channel_id'       => $channelId,
                'upstream_url'     => $upstreamUrl,
                'provider'         => $providerConfig['provider_name'],
                'first_chunk_bytes'=> strlen($firstChunk),
                'wait_ms'          => round((microtime(true) - $waitStart) * 1000, 2),
                'username'         => $username,
                'response_preview' => substr($firstChunk, 0, 200),
            ]);

            return response('Upstream unavailable', 503, ['Content-Type' => 'text/plain']);
        }

        // ── Phase 3: stream response ──────────────────────────────────────────
        // We have valid data. Capture the first chunk then switch to direct piping.
        $contentType  = str_ends_with($streamId, '.m3u8')
            ? 'application/vnd.apple.mpegurl'
            : 'video/mp2t';
        $initialData  = $firstChunk;

        return response()->stream(function () use ($mh, $ch, $initialData, &$headersSent) {
            set_time_limit(0);
            ignore_user_abort(true);

            // Send the first chunk (already received) immediately
            echo $initialData;
            if (ob_get_level()) ob_flush();
            flush();

            // Switch write callback to direct-pipe mode
            $headersSent = true;

            // Continue piping until provider closes or client disconnects
            $active = 1;
            do {
                curl_multi_exec($mh, $active);
                curl_multi_select($mh, 0.05);
            } while ($active && connection_status() === 0);

            curl_multi_remove_handle($mh, $ch);
            curl_multi_close($mh);
            curl_close($ch);
        }, 200, [
            'Content-Type'      => $contentType,
            'Cache-Control'     => 'no-cache, no-store, must-revalidate',
            'X-Accel-Buffering' => 'no',
            'Transfer-Encoding' => 'chunked',
            'Connection'        => 'keep-alive',
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------


    /**
     * Get a base Channel query scoped to this account's M3U source.
     *
     * If the account has no source assigned, returns an empty query
     * so the client sees no channels (rather than the global pool).
     */
    private function accountChannels(IptvAccount $account): \Illuminate\Database\Eloquent\Builder
    {
        $query    = Channel::active()->orderBy('sort_order');
        $sourceId = $account->m3u_source_id ?? null;

        if ($sourceId) {
            $query->where('m3u_source_id', $sourceId);
        } else {
            // No source assigned — return nothing rather than leaking global channels
            $query->whereRaw('1 = 0');
        }

        return $query;
    }

    private function logAccess(Request $request, IptvAccount $account, string $action, string $status): void
    {
        try {
            AccessLog::create([
                'account_id' => $account->id,
                'ip_address' => $request->ip(),
                'username'   => $account->username,
                'action'     => $action,
                'status'     => $status,
                'user_agent' => $request->userAgent(),
            ]);
        } catch (\Throwable) {
            // Never fail a stream request due to logging
        }
    }
}
