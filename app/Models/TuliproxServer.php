<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TuliproxServer extends Model
{
    protected $fillable = [
        'name',
        'protocol',
        'host',
        'port',
        'timezone',
        'message',
        'is_default',
        'is_active',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'protocol' => 'http',
        'port' => '8901',
        'timezone' => 'UTC',
        'is_default' => false,
        'is_active' => true,
    ];

    /**
     * Get the users assigned to this server
     */
    public function iptvUsers(): HasMany
    {
        return $this->hasMany(IptvUser::class);
    }

    /**
     * Get the full URL of the server
     */
    public function getUrlAttribute(): string
    {
        return "{$this->protocol}://{$this->host}:{$this->port}";
    }

    /**
     * Scope to get the default server
     */
    public function scopeDefault($query)
    {
        return $query->where('is_default', true)->where('is_active', true)->first();
    }

    /**
     * Scope to get active servers
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Set this server as the default (unset others)
     */
    public function setAsDefault(): void
    {
        static::query()->update(['is_default' => false]);
        $this->update(['is_default' => true]);
    }
}
