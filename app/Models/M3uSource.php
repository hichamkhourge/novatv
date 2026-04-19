<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class M3uSource extends Model
{
    protected $fillable = [
        'name',
        'source_type',
        // M3U (URL or file)
        'url',
        'file_path',
        // Xtream Codes API
        'xtream_host',
        'xtream_username',
        'xtream_password',
        'xtream_stream_types',
        // Shared options
        'excluded_groups',
        // Status
        'status',
        'is_active',
        'channels_count',
        'error_message',
        'last_synced_at',
    ];

    protected $casts = [
        'is_active'           => 'boolean',
        'last_synced_at'      => 'datetime',
        'xtream_stream_types' => 'array',
        'excluded_groups'     => 'array',
    ];

    protected $attributes = [
        'status'        => 'idle',
        'is_active'     => true,
        'channels_count' => 0,
        'source_type'   => 'url',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class);
    }

    public function iptvAccounts(): HasMany
    {
        return $this->hasMany(IptvAccount::class);
    }

    // ── Type helpers ──────────────────────────────────────────────────────────

    public function isXtream(): bool
    {
        return $this->source_type === 'xtream';
    }

    public function isFileSource(): bool
    {
        return $this->source_type === 'file';
    }

    public function isUrlSource(): bool
    {
        return $this->source_type === 'url';
    }

    /**
     * Get the Xtream API base URL (for player_api.php calls).
     */
    public function xtreamApiBase(): ?string
    {
        if (! $this->isXtream() || ! $this->xtream_host) {
            return null;
        }
        return rtrim($this->xtream_host, '/')
            . '/player_api.php'
            . '?username=' . urlencode($this->xtream_username ?? '')
            . '&password=' . urlencode($this->xtream_password ?? '');
    }

    /**
     * Get the full path to an uploaded M3U file.
     */
    public function getFullFilePath(): ?string
    {
        if (! $this->isFileSource() || ! $this->file_path) {
            return null;
        }
        return storage_path('app/' . $this->file_path);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeNeedsSync(Builder $query): Builder
    {
        return $query->where('status', '!=', 'syncing')
            ->whereIn('status', ['idle', 'error'])
            ->where('is_active', true);
    }
}
