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
}
