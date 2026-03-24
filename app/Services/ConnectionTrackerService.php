<?php

namespace App\Services;

use App\Models\IptvUser;
use App\Models\StreamSession;
use Illuminate\Http\Request;
use Carbon\Carbon;

class ConnectionTrackerService
{
    private const ACTIVE_THRESHOLD_SECONDS = 30;

    public function register(IptvUser $user, string $streamId, Request $request): bool
    {
        // Check if max connections would be exceeded
        $activeCount = $this->getActiveCount($user);

        if ($activeCount >= $user->max_connections) {
            return false;
        }

        // Create or update session
        $session = StreamSession::updateOrCreate(
            [
                'iptv_user_id' => $user->id,
                'stream_id' => $streamId,
                'ip_address' => $request->ip(),
            ],
            [
                'user_agent' => $request->userAgent(),
                'started_at' => now(),
                'last_seen_at' => now(),
            ]
        );

        return true;
    }

    public function heartbeat(int $sessionId): void
    {
        StreamSession::where('id', $sessionId)
            ->update(['last_seen_at' => now()]);
    }

    public function getActiveCount(IptvUser $user): int
    {
        $threshold = Carbon::now()->subSeconds(self::ACTIVE_THRESHOLD_SECONDS);

        return StreamSession::where('iptv_user_id', $user->id)
            ->where('last_seen_at', '>=', $threshold)
            ->count();
    }

    public function getActiveSessions(IptvUser $user)
    {
        $threshold = Carbon::now()->subSeconds(self::ACTIVE_THRESHOLD_SECONDS);

        return StreamSession::where('iptv_user_id', $user->id)
            ->where('last_seen_at', '>=', $threshold)
            ->get();
    }

    public function cleanupStale(): int
    {
        $threshold = Carbon::now()->subSeconds(60);

        return StreamSession::where('last_seen_at', '<', $threshold)
            ->delete();
    }
}
