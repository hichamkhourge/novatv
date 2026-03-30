<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
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
     * Stream sessions for this user
     */
    public function streamSessions(): HasMany
    {
        return $this->hasMany(StreamSession::class);
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
        return $this->m3uSources()
            ->where('is_active', true)
            ->with(['channels' => function ($query) {
                $query->active()->whereNull('deleted_at');
            }])
            ->get()
            ->pluck('channels')
            ->flatten()
            ->unique('id');
    }
}
