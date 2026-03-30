<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Channel extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'm3u_source_id',
        'stream_id',
        'name',
        'stream_url',
        'logo',
        'category',
        'epg_id',
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
     * Scope to filter by active channels only
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
