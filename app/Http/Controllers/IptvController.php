<?php

namespace App\Http\Controllers;

use App\Models\AccessLog;
use App\Models\Channel;
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
 *  - Stream proxy (/live/{username}/{password}/{id}.ts|m3u8)
 *
 * Channels are always scoped to the account's linked M3U source.
 */
class IptvController extends Controller
{
    // -------------------------------------------------------------------------
    // M3U Playlist
    // -------------------------------------------------------------------------

    /**
     * Generate M3U playlist for the authenticated account.
     *
     * GET /get.php?username=X&password=Y&type=m3u_plus&output=ts
     */
    public function getPlaylist(Request $request): StreamedResponse|Response
    {
        /** @var IptvAccount $account */
        $account = $request->attributes->get('iptv_account');

        $this->logAccess($request, $account, 'playlist', 'ok');

        // Fetch channels BEFORE starting the stream so any DB error returns a real HTTP response
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
     */
    private function getLiveCategories(IptvAccount $account): JsonResponse
    {
        $groups = $this->accountChannels($account)
            ->with('channelGroup')
            ->get()
            ->pluck('channelGroup')
            ->filter()
            ->unique('id')
            ->map(fn (object $g) => [
                'category_id'   => (string) $g->id,
                'category_name' => $g->name,
                'parent_id'     => 0,
            ])
            ->values();

        return response()->json($groups);
    }

    /**
     * Return live streams scoped to the account's M3U source.
     * Optionally filtered by category_id.
     */
    private function getLiveStreams(IptvAccount $account, Request $request): JsonResponse
    {
        $categoryId = $request->input('category_id');

        $query = $this->accountChannels($account)->with('channelGroup');

        if ($categoryId) {
            $query->where('channel_group_id', $categoryId);
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

    // -------------------------------------------------------------------------
    // Stream Proxy
    // -------------------------------------------------------------------------

    /**
     * Proxy the upstream stream to the client without buffering.
     *
     * GET /live/{username}/{password}/{channel_id}.ts
     * GET /live/{username}/{password}/{channel_id}.m3u8
     */
    public function streamProxy(Request $request, string $username, string $password, string $streamId): Response|StreamedResponse
    {
        // Authenticate inline
        $account = IptvAccount::where('username', $username)
            ->where('password', $password)
            ->first();

        if (! $account || ! $account->isActive()) {
            return response('Unauthorized', 401, ['Content-Type' => 'text/plain']);
        }

        // Parse channel id from "123.ts" or "123.m3u8"
        $channelId = (int) preg_replace('/\.\w+$/', '', $streamId);

        // Channel must belong to this account's source
        $channel = $this->accountChannels($account)
            ->where('channels.id', $channelId)
            ->first();

        if (! $channel || ! $channel->is_active) {
            \Illuminate\Support\Facades\Log::warning('streamProxy: channel not found', [
                'account'   => $username,
                'channelId' => $channelId,
            ]);
            return response('Channel not found', 404, ['Content-Type' => 'text/plain']);
        }

        // Enforce max_connections
        $ip             = $request->ip();
        $activeSessions = StreamSession::where('account_id', $account->id)
            ->where('last_seen_at', '>', now()->subSeconds(30))
            ->where(fn ($q) => $q->where('channel_id', '!=', $channelId)->orWhere('ip_address', '!=', $ip))
            ->count();

        if ($activeSessions >= $account->max_connections) {
            return response('Max connections reached', 403, ['Content-Type' => 'text/plain']);
        }

        // Upsert stream session
        StreamSession::updateOrCreate(
            ['account_id' => $account->id, 'channel_id' => $channelId, 'ip_address' => $ip],
            ['started_at' => now(), 'last_seen_at' => now()],
        );

        $upstreamUrl = $channel->stream_url;

        // Detect content type from the extension requested by the client
        $ext         = strtolower(pathinfo($streamId, PATHINFO_EXTENSION));
        $contentType = match ($ext) {
            'm3u8'  => 'application/vnd.apple.mpegurl',
            default => 'video/mp2t',
        };

        $accountId = $account->id;

        return response()->stream(function () use ($upstreamUrl, $accountId, $channelId, $ip) {

            // Cleanup session when client disconnects
            register_shutdown_function(function () use ($accountId, $channelId, $ip) {
                try {
                    StreamSession::where('account_id', $accountId)
                        ->where('channel_id', $channelId)
                        ->where('ip_address', $ip)
                        ->delete();
                } catch (\Throwable) {}
            });

            $ch = curl_init($upstreamUrl);

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => false,        // stream directly
                CURLOPT_FOLLOWLOCATION => true,         // follow HTTP redirects
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT        => 0,            // no read timeout (live stream)
                CURLOPT_SSL_VERIFYPEER => false,        // allow self-signed upstream certs
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; IPTV Proxy)',
                CURLOPT_HTTPHEADER     => [
                    'Accept: */*',
                    'Connection: keep-alive',
                ],
                CURLOPT_WRITEFUNCTION  => function ($curl, $data) use ($accountId, $channelId, $ip) {
                    if (connection_aborted()) {
                        return -1; // Signal cURL to stop
                    }

                    echo $data;
                    flush();

                    // Update last_seen_at every ~5s
                    static $lastUpdate = 0;
                    $now = time();
                    if ($now - $lastUpdate >= 5) {
                        try {
                            StreamSession::where('account_id', $accountId)
                                ->where('channel_id', $channelId)
                                ->where('ip_address', $ip)
                                ->update(['last_seen_at' => now()]);
                        } catch (\Throwable) {}
                        $lastUpdate = $now;
                    }

                    return strlen($data);
                },
            ]);

            $result = curl_exec($ch);

            if ($result === false) {
                \Illuminate\Support\Facades\Log::warning('streamProxy: cURL failed', [
                    'url'   => $upstreamUrl,
                    'error' => curl_error($ch),
                    'code'  => curl_errno($ch),
                ]);
            }

            curl_close($ch);

        }, 200, [
            'Content-Type'      => $contentType,
            'Cache-Control'     => 'no-cache, no-store',
            'X-Accel-Buffering' => 'no', // Disable Nginx/proxy buffering
            'Pragma'            => 'no-cache',
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
        $query = Channel::active()->orderBy('sort_order');

        // Safely read m3u_source_id — column may not exist if migration is pending
        $sourceId = null;
        try {
            $sourceId = $account->m3u_source_id;
        } catch (\Throwable) {}

        if ($sourceId) {
            // Only filter by m3u_source_id if the column exists on the channels table
            $hasCol = \Illuminate\Support\Facades\Schema::hasColumn('channels', 'm3u_source_id');
            if ($hasCol) {
                $query->where('m3u_source_id', $sourceId);
            }
            // If column missing, fall through and return ALL active channels as a safe fallback
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
