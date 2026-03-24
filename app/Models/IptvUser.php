<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IptvUser extends Model
{
    protected $fillable = [
        'username',
        'password',
        'email',
        'max_connections',
        'is_active',
        'expires_at',
        'package_id',
        'm3u_source_id',
        'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'expires_at' => 'datetime',
    ];

    protected $hidden = [
        'password',
    ];

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function m3uSource(): BelongsTo
    {
        return $this->belongsTo(M3uSource::class);
    }

    public function streamSessions(): HasMany
    {
        return $this->hasMany(StreamSession::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function isValid(): bool
    {
        return $this->is_active && !$this->isExpired();
    }
}
