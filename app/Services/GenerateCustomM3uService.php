<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\IptvAccount;
use Illuminate\Support\Facades\Cache;

class GenerateCustomM3uService
{
    /**
     * Generate a custom M3U playlist for an account based on their preferences.
     *
     * @param IptvAccount $account
     * @param array $options Optional filters: ['search' => 'ESPN', 'groups' => [1, 2, 3]]
     * @return string M3U playlist content
     */
    public function generate(IptvAccount $account, array $options = []): string
    {
        // Build cache key
        $cacheKey = $this->buildCacheKey($account, $options);

        // Cache for 5 minutes
        return Cache::remember($cacheKey, 300, function () use ($account, $options) {
            return $this->buildPlaylist($account, $options);
        });
    }

    /**
     * Build the M3U playlist content.
     *
     * @param IptvAccount $account
     * @param array $options
     * @return string
     */
    protected function buildPlaylist(IptvAccount $account, array $options = []): string
    {
        // Get enabled channels query
        $query = $account->getEnabledChannelsQuery();

        // Apply search filter if provided
        if (!empty($options['search'])) {
            $search = $options['search'];
            $query->where(function ($q) use ($search) {
                $q->where('channels.name', 'ILIKE', "%{$search}%")
                    ->orWhere('channels.tvg_name', 'ILIKE', "%{$search}%");
            });
        }

        // Apply specific group filter if provided
        if (!empty($options['groups'])) {
            $groups = is_array($options['groups']) ? $options['groups'] : explode(',', $options['groups']);
            $query->whereIn('channel_group_id', $groups);
        }

        // Load channels with their groups
        $channels = $query->with('channelGroup')
            ->orderBy('channel_group_id')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        // Build M3U content
        $m3u = "#EXTM3U\n";

        foreach ($channels as $channel) {
            $m3u .= $this->buildExtinfLine($channel);
            $m3u .= $channel->stream_url . "\n";
        }

        return $m3u;
    }

    /**
     * Build the #EXTINF line for a channel.
     *
     * @param Channel $channel
     * @return string
     */
    protected function buildExtinfLine(Channel $channel): string
    {
        $attributes = [];

        if ($channel->tvg_id) {
            $attributes[] = 'tvg-id="' . htmlspecialchars($channel->tvg_id, ENT_QUOTES) . '"';
        }

        if ($channel->tvg_name) {
            $attributes[] = 'tvg-name="' . htmlspecialchars($channel->tvg_name, ENT_QUOTES) . '"';
        }

        if ($channel->logo_url) {
            $attributes[] = 'tvg-logo="' . htmlspecialchars($channel->logo_url, ENT_QUOTES) . '"';
        }

        if ($channel->channelGroup) {
            $attributes[] = 'group-title="' . htmlspecialchars($channel->channelGroup->name, ENT_QUOTES) . '"';
        }

        $attributeString = implode(' ', $attributes);
        $channelName = htmlspecialchars($channel->name, ENT_QUOTES);

        return "#EXTINF:-1 {$attributeString},{$channelName}\n";
    }

    /**
     * Build cache key for the playlist.
     *
     * @param IptvAccount $account
     * @param array $options
     * @return string
     */
    protected function buildCacheKey(IptvAccount $account, array $options = []): string
    {
        $key = "m3u_playlist_{$account->id}";

        if (!empty($options['search'])) {
            $key .= '_search_' . md5($options['search']);
        }

        if (!empty($options['groups'])) {
            $groups = is_array($options['groups']) ? $options['groups'] : explode(',', $options['groups']);
            sort($groups);
            $key .= '_groups_' . implode('_', $groups);
        }

        // Include updated_at timestamp to invalidate cache when account is modified
        $key .= '_' . $account->updated_at->timestamp;

        return $key;
    }

    /**
     * Clear the cache for an account's playlists.
     *
     * @param IptvAccount $account
     * @return void
     */
    public function clearCache(IptvAccount $account): void
    {
        // Since we include updated_at in cache key, just updating the account will invalidate cache
        // But we can also manually clear with pattern matching if needed
        $pattern = "m3u_playlist_{$account->id}_*";

        // Note: This requires Redis for pattern-based deletion
        // For other cache drivers, the updated_at timestamp approach will work
        if (config('cache.default') === 'redis') {
            $keys = Cache::getRedis()->keys($pattern);
            foreach ($keys as $key) {
                Cache::forget(str_replace(config('database.redis.options.prefix'), '', $key));
            }
        }
    }
}
