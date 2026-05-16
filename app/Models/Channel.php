<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Channel extends Model
{
    protected $fillable = [
        'channel_group_id',
        'm3u_source_id',
        'name',
        'stream_url',
        'logo_url',
        'tvg_id',
        'tvg_name',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    /** The M3U source this channel was imported from */
    public function m3uSource(): BelongsTo
    {
        return $this->belongsTo(M3uSource::class);
    }

    /** The channel group this channel belongs to */
    public function channelGroup(): BelongsTo
    {
        return $this->belongsTo(ChannelGroup::class);
    }

    /** Scope to filter active channels only */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('channels.is_active', true);
    }

    /**
     * Scope to filter channels enabled for a specific account.
     * Respects group restrictions and individual channel preferences.
     *
     * @param Builder $query
     * @param int $accountId
     * @return Builder
     */
    public function scopeEnabledFor(Builder $query, int $accountId): Builder
    {
        $account = IptvAccount::find($accountId);

        if (!$account) {
            return $query->whereRaw('1 = 0');
        }

        // Get enabled groups for this account
        $enabledGroups = $account->getEnabledChannelGroups();
        $enabledGroupIds = $enabledGroups->pluck('id');

        if ($enabledGroupIds->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        // Filter by source, active status, and enabled groups
        $query->where('m3u_source_id', $account->m3u_source_id)
            ->where('is_active', true)
            ->whereIn('channel_group_id', $enabledGroupIds);

        // Exclude individually disabled channels
        $disabledChannelIds = AccountChannelPreference::query()
            ->where('account_id', $accountId)
            ->where('is_enabled', false)
            ->pluck('channel_id');

        if ($disabledChannelIds->isNotEmpty()) {
            $query->whereNotIn('id', $disabledChannelIds);
        }

        return $query;
    }
}
