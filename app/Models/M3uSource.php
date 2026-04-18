<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class M3uSource extends Model
{
    protected $fillable = [
        'name',
        'url',
        'source_type',
        'file_path',
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
        'source_type' => 'url',
    ];

    /**
     * Channels belonging to this M3U source
     */
    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class);
    }

    /**
     * IPTV accounts that use this M3U source
     */
    public function iptvAccounts(): HasMany
    {
        return $this->hasMany(IptvAccount::class);
    }

    /**
     * Check if this source uses a file upload
     */
    public function isFileSource(): bool
    {
        return $this->source_type === 'file';
    }

    /**
     * Get the full path to the uploaded file
     */
    public function getFullFilePath(): ?string
    {
        if (!$this->isFileSource() || !$this->file_path) {
            return null;
        }

        return storage_path('app/' . $this->file_path);
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
