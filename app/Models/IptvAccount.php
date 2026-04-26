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
    ];

    protected $casts = [
        'expires_at'      => 'datetime',
        'max_connections' => 'integer',
    ];

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
     * Empty = access to all active groups.
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
        $explicit = $this->channelGroups;

        return $explicit->isNotEmpty()
            ? $explicit->where('is_active', true)->values()
            : ChannelGroup::active()
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get();
    }
}
