<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Channel extends Model
{
    protected $fillable = [
        'name',
        'tvg_id',
        'tvg_name',
        'tvg_logo',
        'group_name',
        'stream_url',
        'm3u_source_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Get the M3U source that owns this channel
     */
    public function m3uSource(): BelongsTo
    {
        return $this->belongsTo(M3uSource::class);
    }

    /**
     * Get the users that have access to this channel
     */
    public function iptvUsers(): BelongsToMany
    {
        return $this->belongsToMany(IptvUser::class, 'iptv_user_channel');
    }

    /**
     * Scope to filter by group name
     */
    public function scopeByGroup($query, string $groupName)
    {
        return $query->where('group_name', $groupName);
    }

    /**
     * Scope to filter by active channels
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to filter by M3U source
     */
    public function scopeBySource($query, int $sourceId)
    {
        return $query->where('m3u_source_id', $sourceId);
    }
}
