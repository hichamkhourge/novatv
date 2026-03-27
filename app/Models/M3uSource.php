<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class M3uSource extends Model
{
    protected $fillable = [
        'name',
        'target_name',
        'url',
        'source_type',
        'file_path',
        'is_active',
        'use_direct_urls',
        'last_fetched_at',
        'provider_type',
        'provider_username',
        'provider_password',
        'provider_config',
        'script_path',
        'automation_enabled',
        'last_automation_run',
        'automation_status',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'use_direct_urls' => 'boolean',
        'last_fetched_at' => 'datetime',
        'automation_enabled' => 'boolean',
        'last_automation_run' => 'datetime',
        'provider_config' => 'array',
    ];

    protected $attributes = [
        'source_type' => 'url',
        'provider_type' => 'none',
        'automation_enabled' => false,
    ];

    public function iptvUsers(): HasMany
    {
        return $this->hasMany(IptvUser::class);
    }

    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class);
    }

    /**
     * Get the target name, generating from name if not set
     */
    public function getTargetNameAttribute($value): string
    {
        return $value ?? \Illuminate\Support\Str::slug($this->name);
    }
}
