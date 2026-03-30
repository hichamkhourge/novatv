<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class M3uSource extends Model
{
    protected $fillable = [
        'name',
        'url',
        'status',
        'is_active',
        'channels_count',
        'error_message',
        'last_synced_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_synced_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => 'idle',
        'is_active' => true,
        'channels_count' => 0,
    ];

    /**
     * Channels belonging to this M3U source
     */
    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class);
    }

    /**
     * IPTV users linked to this M3U source (many-to-many)
     */
    public function iptvUsers(): BelongsToMany
    {
        return $this->belongsToMany(IptvUser::class, 'user_sources')
            ->withTimestamps();
    }

    /**
     * Scope to get only active sources
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to get sources that need syncing
     * (status is idle or error, and not syncing)
     */
    public function scopeNeedsSync(Builder $query): Builder
    {
        return $query->where('status', '!=', 'syncing')
            ->whereIn('status', ['idle', 'error'])
            ->where('is_active', true);
    }
}
