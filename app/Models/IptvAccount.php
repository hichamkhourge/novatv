<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IptvAccount extends Model
{
    protected $fillable = [
        'username',
        'password',
        'max_connections',
        'expires_at',
        'status',
        'notes',
        'm3u_source_id',
        'has_group_restrictions',
        'allow_adult',
        // Provider automation
        'provider',
        'provider_account_id',
        'provider_status',
        'provider_error',
        'provider_synced_at',
    ];

    protected $casts = [
        'expires_at'             => 'datetime',
        'max_connections'        => 'integer',
        'has_group_restrictions' => 'boolean',
        'allow_adult'            => 'boolean',
        'provider_synced_at'     => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (IptvAccount $account): void {
            if ($account->isDirty('m3u_source_id')) {
                $account->has_group_restrictions = false;
            }
        });

        static::updated(function (IptvAccount $account): void {
            if ($account->wasChanged('m3u_source_id')) {
                $account->channelGroups()->detach();
            }
        });
    }

    /**
     * The M3U source this account is linked to.
     * Channels served to this user come from this source only.
     */
    public function m3uSource(): BelongsTo
    {
        return $this->belongsTo(M3uSource::class);
    }

    /**
     * Active stream sessions for this account.
     */
    public function streamSessions(): HasMany
    {
        return $this->hasMany(StreamSession::class, 'account_id');
    }

    /**
     * Access log entries for this account.
     */
    public function accessLogs(): HasMany
    {
        return $this->hasMany(AccessLog::class, 'account_id');
    }

    /**
     * Channel groups this account has explicit access to.
     * Whether this list is enforced is controlled by has_group_restrictions.
     */
    public function channelGroups(): BelongsToMany
    {
        return $this->belongsToMany(ChannelGroup::class, 'account_channel_groups', 'account_id', 'channel_group_id')
            ->withPivot('sort_order')
            ->orderBy('account_channel_groups.sort_order')
            ->orderBy('channel_groups.sort_order')
            ->orderBy('channel_groups.name');
    }

    /**
     * Check whether this account's subscription has expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Check whether this account is usable (status active and not expired).
     */
    public function isActive(): bool
    {
        return $this->status === 'active' && ! $this->isExpired();
    }

    /**
     * Scope to only active, non-expired accounts.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active')
            ->where(function (Builder $q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    /**
     * Get the resolved channel groups for this account:
     * explicit groups if assigned, otherwise ALL active groups.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, ChannelGroup>
     */
    public function resolvedChannelGroups()
    {
        $sourceId = $this->m3u_source_id;

        if (! $sourceId) {
            return ChannelGroup::query()->whereRaw('1 = 0')->get();
        }

        $baseQuery = ChannelGroup::query()
            ->where('channel_groups.is_active', true)
            ->when(! $this->allow_adult, fn (Builder $query) => $query->where('channel_groups.is_adult', false))
            ->whereExists(function ($sub) use ($sourceId) {
                $sub->selectRaw('1')
                    ->from('channels')
                    ->whereColumn('channels.channel_group_id', 'channel_groups.id')
                    ->where('channels.m3u_source_id', $sourceId)
                    ->where('channels.is_active', true);
            })
            ->orderBy('channel_groups.sort_order')
            ->orderBy('channel_groups.name');

        $allowedGroupIds = $baseQuery->pluck('channel_groups.id');

        if (! $this->has_group_restrictions) {
            if ($allowedGroupIds->isEmpty()) {
                return ChannelGroup::query()->whereRaw('1 = 0')->get();
            }

            return ChannelGroup::query()
                ->whereIn('channel_groups.id', $allowedGroupIds)
                ->orderBy('channel_groups.sort_order')
                ->orderBy('channel_groups.name')
                ->get();
        }

        if ($allowedGroupIds->isEmpty()) {
            return ChannelGroup::query()->whereRaw('1 = 0')->get();
        }

        $restricted = $this->channelGroups()
            ->whereIn('channel_groups.id', $allowedGroupIds)
            ->orderBy('account_channel_groups.sort_order')
            ->orderBy('channel_groups.sort_order')
            ->orderBy('channel_groups.name')
            ->get();

        if ($restricted->isEmpty()) {
            return ChannelGroup::query()->whereRaw('1 = 0')->get();
        }

        return $restricted->values();
    }
}
