<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\IptvUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Xtream Codes API Compatible Controller
 * Implements player_api.php and stream proxy endpoints
 */
class XtreamController extends Controller
{
    /**
     * Main Xtream API endpoint
     * Handles multiple actions based on ?action= parameter
     *
     * GET|POST /player_api.php
     */
    public function playerApi(Request $request): JsonResponse
    {
        $action = $request->input('action', 'get_account_info');
        $user = $request->input('iptv_user'); // Injected by XtreamAuth middleware

        return match ($action) {
            'get_account_info', null => $this->getAccountInfo($user),
            'get_live_categories' => $this->getLiveCategories($user),
            'get_live_streams' => $this->getLiveStreams($user, $request),
            'get_vod_categories' => $this->getVodCategories(),
            'get_vod_streams' => $this->getVodStreams(),
            'get_series_categories' => $this->getSeriesCategories(),
            'get_series' => $this->getSeries(),
            'get_short_epg' => $this->getShortEpg($request),
            default => response()->json(['error' => 'Invalid action'], 400),
        };
    }

    /**
     * Get account information
     */
    private function getAccountInfo(IptvUser $user): JsonResponse
    {
        return response()->json([
            'user_info' => [
                'username' => $user->username,
                'password' => $user->password,
                'status' => $user->is_active ? 'Active' : 'Inactive',
                'exp_date' => $user->expires_at ? $user->expires_at->timestamp : null,
                'is_trial' => '0',
                'active_cons' => '0',
                'max_connections' => (string) $user->max_connections,
                'allowed_output_formats' => ['ts', 'm3u8'],
            ],
            'server_info' => [
                'url' => parse_url(config('app.url'), PHP_URL_HOST),
                'port' => '80',
                'https_port' => '443',
                'server_protocol' => parse_url(config('app.url'), PHP_URL_SCHEME) ?: 'https',
                'rtmp_port' => '1935',
                'timezone' => config('app.timezone'),
                'timestamp_now' => time(),
                'time_now' => now()->toDateTimeString(),
            ],
        ]);
    }

    /**
     * Get live stream categories
     */
    private function getLiveCategories(IptvUser $user): JsonResponse
    {
        $categories = $user->allChannels()
            ->pluck('category')
            ->filter()
            ->unique()
            ->values()
            ->map(function ($category, $index) {
                return [
                    'category_id' => md5($category),
                    'category_name' => $category,
                    'parent_id' => 0,
                ];
            });

        return response()->json($categories);
    }

    /**
     * Get live streams for user
     */
    private function getLiveStreams(IptvUser $user, Request $request): JsonResponse
    {
        $category = $request->input('category_id');
        $start = (int) $request->input('start', 0);
        $limit = (int) $request->input('limit', 10000);

        $channels = $user->allChannels();

        // Filter by category if provided
        if ($category) {
            $channels = $channels->filter(function ($channel) use ($category) {
                return $channel->category && md5($channel->category) === $category;
            });
        }

        // Apply pagination
        $channels = $channels->slice($start, $limit)->values();

        $streams = $channels->map(function ($channel, $index) use ($start) {
            return [
                'num' => $start + $index + 1,
                'name' => $channel->name,
                'stream_type' => 'live',
                'stream_id' => $channel->id,
                'stream_icon' => $channel->logo,
                'epg_channel_id' => $channel->epg_id,
                'category_id' => $channel->category ? md5($channel->category) : null,
                'direct_source' => $channel->stream_url,
            ];
        });

        return response()->json($streams);
    }

    /**
     * VOD categories (stub - return empty array)
     */
    private function getVodCategories(): JsonResponse
    {
        return response()->json([]);
    }

    /**
     * VOD streams (stub - return empty array)
     */
    private function getVodStreams(): JsonResponse
    {
        return response()->json([]);
    }

    /**
     * Series categories (stub - return empty array)
     */
    private function getSeriesCategories(): JsonResponse
    {
        return response()->json([]);
    }

    /**
     * Series (stub - return empty array)
     */
    private function getSeries(): JsonResponse
    {
        return response()->json([]);
    }

    /**
     * Short EPG (stub - return empty array)
     */
    private function getShortEpg(Request $request): JsonResponse
    {
        return response()->json([]);
    }

    /**
     * Generate M3U playlist
     *
     * GET /get.php?username=X&password=Y&type=m3u_plus&output=ts
     */
    public function getPlaylist(Request $request): Response
    {
        $user = $request->input('iptv_user'); // Injected by XtreamAuth middleware
        $channels = $user->allChannels();

        // Stream response to avoid memory issues with large playlists
        return response()->stream(function () use ($user, $channels) {
            echo "#EXTM3U\r\n";

            foreach ($channels as $channel) {
                $streamUrl = config('app.url') . "/live/{$user->username}/{$user->password}/{$channel->id}.ts";

                echo sprintf(
                    "#EXTINF:-1 tvg-id=\"%s\" tvg-name=\"%s\" tvg-logo=\"%s\" group-title=\"%s\",%s\r\n%s\r\n",
                    $channel->epg_id ?? '',
                    $channel->name,
                    $channel->logo ?? '',
                    $channel->category ?? 'Uncategorized',
                    $channel->name,
                    $streamUrl
                );
            }
        }, 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="playlist.m3u"',
        ]);
    }

    /**
     * Stream proxy - redirect to real stream URL
     *
     * GET /live/{username}/{password}/{stream_id}.ts
     * GET /live/{username}/{password}/{stream_id}.m3u8
     */
    public function streamProxy(Request $request, string $username, string $password, string $streamId): Response
    {
        // Authenticate user
        $user = IptvUser::where('username', $username)
            ->where('password', $password)
            ->first();

        if (!$user || !$user->isValid()) {
            return response('Unauthorized', 401);
        }

        // Extract channel ID (remove extension)
        $channelId = (int) preg_replace('/\.(ts|m3u8)$/', '', $streamId);

        // Find channel
        $channel = Channel::find($channelId);

        if (!$channel) {
            return response('Channel not found', 404);
        }

        // Verify channel belongs to one of user's sources
        $userSourceIds = $user->m3uSources()->pluck('m3u_sources.id');

        if (!$userSourceIds->contains($channel->m3u_source_id)) {
            return response('Access denied', 403);
        }

        // Log connection
        $this->logConnection($user, $channel, $request);

        // Redirect to real stream URL
        return redirect($channel->stream_url, 302);
    }

    /**
     * Log stream connection for analytics
     */
    private function logConnection(IptvUser $user, Channel $channel, Request $request): void
    {
        try {
            DB::table('connection_logs')->insert([
                'iptv_user_id' => $user->id,
                'channel_id' => $channel->id,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'created_at' => now(),
            ]);
        } catch (\Exception $e) {
            // Log but don't fail the stream
            \Log::warning('Failed to log connection', [
                'user_id' => $user->id,
                'channel_id' => $channel->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
