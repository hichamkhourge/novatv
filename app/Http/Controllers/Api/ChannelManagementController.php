<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccountChannelPreference;
use App\Models\Channel;
use App\Models\ChannelGroup;
use App\Models\IptvAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChannelManagementController extends Controller
{
    /**
     * List channel groups for an account with their enabled status.
     *
     * GET /api/accounts/{account}/channel-groups
     */
    public function listGroups(IptvAccount $account): JsonResponse
    {
        $resolvedGroups = $account->resolvedChannelGroups();

        // Get pivot data if has_group_restrictions
        $groupData = $resolvedGroups->map(function ($group) use ($account) {
            $isEnabled = true;

            if ($account->has_group_restrictions) {
                // Get the pivot data
                $pivot = DB::table('account_channel_groups')
                    ->where('account_id', $account->id)
                    ->where('channel_group_id', $group->id)
                    ->first();

                $isEnabled = $pivot ? (bool) ($pivot->is_enabled ?? true) : true;
            }

            // Count channels in this group for this account's source
            $channelCount = Channel::query()
                ->where('channel_group_id', $group->id)
                ->where('m3u_source_id', $account->m3u_source_id)
                ->where('is_active', true)
                ->count();

            return [
                'id' => $group->id,
                'name' => $group->name,
                'slug' => $group->slug,
                'is_adult' => $group->is_adult,
                'is_enabled' => $isEnabled,
                'channel_count' => $channelCount,
                'sort_order' => $group->sort_order,
            ];
        });

        return response()->json([
            'account_id' => $account->id,
            'has_group_restrictions' => $account->has_group_restrictions,
            'groups' => $groupData,
        ]);
    }

    /**
     * Toggle a channel group on/off for an account.
     *
     * PATCH /api/accounts/{account}/channel-groups/{group}
     * Body: {"is_enabled": true|false}
     */
    public function toggleGroup(Request $request, IptvAccount $account, ChannelGroup $group): JsonResponse
    {
        $request->validate([
            'is_enabled' => 'required|boolean',
        ]);

        // Enable group restrictions if not already enabled
        if (!$account->has_group_restrictions) {
            $account->has_group_restrictions = true;
            $account->save();

            // Attach all resolved groups with is_enabled = true by default
            $resolvedGroups = $account->resolvedChannelGroups();
            foreach ($resolvedGroups as $g) {
                DB::table('account_channel_groups')->insert([
                    'account_id' => $account->id,
                    'channel_group_id' => $g->id,
                    'is_enabled' => $g->id === $group->id ? $request->is_enabled : true,
                    'sort_order' => $g->sort_order ?? 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        } else {
            // Update existing pivot
            DB::table('account_channel_groups')
                ->where('account_id', $account->id)
                ->where('channel_group_id', $group->id)
                ->update([
                    'is_enabled' => $request->is_enabled,
                    'updated_at' => now(),
                ]);
        }

        return response()->json([
            'success' => true,
            'group_id' => $group->id,
            'is_enabled' => $request->is_enabled,
        ]);
    }

    /**
     * List channels in a specific group for an account.
     *
     * GET /api/accounts/{account}/channel-groups/{group}/channels
     * Query params: ?search=ESPN
     */
    public function listChannelsInGroup(Request $request, IptvAccount $account, ChannelGroup $group): JsonResponse
    {
        $query = Channel::query()
            ->where('channel_group_id', $group->id)
            ->where('m3u_source_id', $account->m3u_source_id)
            ->where('is_active', true);

        // Apply search filter
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ILIKE', "%{$search}%")
                    ->orWhere('tvg_name', 'ILIKE', "%{$search}%");
            });
        }

        $channels = $query->orderBy('sort_order')->orderBy('name')->get();

        // Get preferences for these channels
        $preferences = AccountChannelPreference::query()
            ->where('account_id', $account->id)
            ->whereIn('channel_id', $channels->pluck('id'))
            ->pluck('is_enabled', 'channel_id');

        $channelData = $channels->map(function ($channel) use ($preferences) {
            return [
                'id' => $channel->id,
                'name' => $channel->name,
                'tvg_id' => $channel->tvg_id,
                'tvg_name' => $channel->tvg_name,
                'logo_url' => $channel->logo_url,
                'is_enabled' => $preferences[$channel->id] ?? true, // Default to enabled
            ];
        });

        return response()->json([
            'group' => [
                'id' => $group->id,
                'name' => $group->name,
            ],
            'channels' => $channelData,
            'total' => $channelData->count(),
        ]);
    }

    /**
     * Toggle an individual channel on/off for an account.
     *
     * PATCH /api/accounts/{account}/channels/{channel}
     * Body: {"is_enabled": true|false}
     */
    public function toggleChannel(Request $request, IptvAccount $account, Channel $channel): JsonResponse
    {
        $request->validate([
            'is_enabled' => 'required|boolean',
        ]);

        // Verify channel belongs to account's source
        if ($channel->m3u_source_id !== $account->m3u_source_id) {
            return response()->json(['error' => 'Channel not available for this account'], 403);
        }

        // Update or create preference
        AccountChannelPreference::updateOrCreate(
            [
                'account_id' => $account->id,
                'channel_id' => $channel->id,
            ],
            [
                'is_enabled' => $request->is_enabled,
            ]
        );

        return response()->json([
            'success' => true,
            'channel_id' => $channel->id,
            'is_enabled' => $request->is_enabled,
        ]);
    }

    /**
     * Bulk toggle multiple channels.
     *
     * POST /api/accounts/{account}/channels/bulk-toggle
     * Body: {"channel_ids": [1, 2, 3], "is_enabled": false}
     */
    public function bulkToggleChannels(Request $request, IptvAccount $account): JsonResponse
    {
        $request->validate([
            'channel_ids' => 'required|array',
            'channel_ids.*' => 'integer|exists:channels,id',
            'is_enabled' => 'required|boolean',
        ]);

        $channelIds = $request->input('channel_ids');

        // Verify all channels belong to account's source
        $validChannels = Channel::query()
            ->whereIn('id', $channelIds)
            ->where('m3u_source_id', $account->m3u_source_id)
            ->pluck('id');

        if ($validChannels->count() !== count($channelIds)) {
            return response()->json(['error' => 'Some channels are not available for this account'], 403);
        }

        // Bulk upsert preferences
        foreach ($channelIds as $channelId) {
            AccountChannelPreference::updateOrCreate(
                [
                    'account_id' => $account->id,
                    'channel_id' => $channelId,
                ],
                [
                    'is_enabled' => $request->is_enabled,
                ]
            );
        }

        return response()->json([
            'success' => true,
            'updated_count' => count($channelIds),
            'is_enabled' => $request->is_enabled,
        ]);
    }

    /**
     * Search all channels for an account across all groups.
     *
     * GET /api/accounts/{account}/channels/search?q=ESPN
     */
    public function searchChannels(Request $request, IptvAccount $account): JsonResponse
    {
        $request->validate([
            'q' => 'required|string|min:2',
        ]);

        $search = $request->input('q');

        $query = Channel::query()
            ->where('m3u_source_id', $account->m3u_source_id)
            ->where('is_active', true)
            ->where(function ($q) use ($search) {
                $q->where('name', 'ILIKE', "%{$search}%")
                    ->orWhere('tvg_name', 'ILIKE', "%{$search}%");
            })
            ->with('channelGroup');

        $channels = $query->orderBy('name')->limit(100)->get();

        // Get preferences
        $preferences = AccountChannelPreference::query()
            ->where('account_id', $account->id)
            ->whereIn('channel_id', $channels->pluck('id'))
            ->pluck('is_enabled', 'channel_id');

        $channelData = $channels->map(function ($channel) use ($preferences) {
            return [
                'id' => $channel->id,
                'name' => $channel->name,
                'tvg_id' => $channel->tvg_id,
                'tvg_name' => $channel->tvg_name,
                'logo_url' => $channel->logo_url,
                'group' => $channel->channelGroup ? [
                    'id' => $channel->channelGroup->id,
                    'name' => $channel->channelGroup->name,
                ] : null,
                'is_enabled' => $preferences[$channel->id] ?? true,
            ];
        });

        return response()->json([
            'query' => $search,
            'results' => $channelData,
            'total' => $channelData->count(),
        ]);
    }
}
