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
            default => response()->json(['error' => 'Unknown action'], 400),
        };
    }

    public function stream(Request $request, string $username, string $password, string $streamId)
    {
        $user = $this->authenticate($username, $password);

        if (!$user) {
            abort(403, 'Invalid credentials');
        }

        // Remove .ts extension if present
        $streamId = str_replace('.ts', '', $streamId);

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

        // Proxy the stream
        return $this->proxyStream($streamUrl);
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

        // Extract unique group names from channels
        foreach ($channels as $channel) {
            if (isset($channel['group_title']) && !in_array($channel['group_title'], $groupNames)) {
                $groupNames[] = $channel['group_title'];
            }
        }

        sort($groupNames);

        foreach ($groupNames as $index => $groupName) {
            $groups[] = [
                'category_id' => $index + 1,
                'category_name' => $groupName,
                'parent_id' => 0,
            ];
        }

        return response()->json($groups);
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

        foreach ($channels as $channel) {
            $streamId = md5($channel['url'] ?? $channel['tvg_id'] ?? rand());

            $streams[] = [
                'num' => count($streams) + 1,
                'name' => $channel['name'] ?? 'Unknown',
                'stream_type' => 'live',
                'stream_id' => $streamId,
                'stream_icon' => $channel['tvg_logo'] ?? '',
                'epg_channel_id' => $channel['tvg_id'] ?? '',
                'added' => now()->timestamp,
                'category_id' => $categoryId ?? '1',
                'custom_sid' => '',
                'tv_archive' => 0,
                'direct_source' => '',
                'tv_archive_duration' => 0,
            ];
        }

        return response()->json($streams);
    }

    private function proxyStream(string $streamUrl): StreamedResponse
    {
        return response()->stream(function () use ($streamUrl) {
            $stream = Http::withOptions([
                'stream' => true,
                'timeout' => 0,
            ])->get($streamUrl);

            if ($stream->successful()) {
                foreach ($stream->getBody() as $chunk) {
                    echo $chunk;
                    flush();
                }
            }
        }, 200, [
            'Content-Type' => 'video/mp2t',
            'Cache-Control' => 'no-cache',
        ]);
    }
}
