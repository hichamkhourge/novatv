<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class IptvUser extends Model
{
    protected $fillable = [
        'username',
        'password',
        'email',
        'max_connections',
        'is_active',
        'expires_at',
        'notes',
        'm3u_source_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'expires_at' => 'datetime',
    ];

    protected $hidden = [
        'password',
    ];

    /**
     * M3U sources linked to this user (many-to-many)
     */
    public function m3uSources(): BelongsToMany
    {
        return $this->belongsToMany(M3uSource::class, 'user_sources')
            ->withTimestamps();
    }

    /**
     * Dedicated M3U source for this user (one-to-one)
     */
    public function m3uSource(): BelongsTo
    {
        return $this->belongsTo(M3uSource::class);
    }

    /**
     * Connection logs for this user
     */
    public function connectionLogs(): HasMany
    {
        return $this->hasMany(ConnectionLog::class);
    }

    /**
     * Check if user subscription is expired
     */
    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    /**
     * Check if user is valid (active and not expired)
     */
    public function isValid(): bool
    {
        return $this->is_active && !$this->isExpired();
    }

    /**
     * Get all active channels from all linked M3U sources
     * Returns a merged collection of channels
     */
    public function allChannels(): Collection
    {
        $sources = collect();

        // Include dedicated M3U source if exists
        if ($this->m3u_source_id && $this->m3uSource) {
            $sources->push($this->m3uSource);
        }

        // Include many-to-many sources (for backward compatibility)
        $manyToManySources = $this->m3uSources()
            ->where('is_active', true)
            ->with(['channels' => function ($query) {
                $query->active()->whereNull('deleted_at');
            }])
            ->get();

        $sources = $sources->merge($manyToManySources);

        return $sources
            ->where('is_active', true)
            ->pluck('channels')
            ->flatten()
            ->unique('id');
    }
}
