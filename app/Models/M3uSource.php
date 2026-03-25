<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class M3uSource extends Model
{
    protected $fillable = [
        'name',
        'url',
        'source_type',
        'file_path',
        'is_active',
        'use_direct_urls',
        'last_fetched_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'use_direct_urls' => 'boolean',
        'last_fetched_at' => 'datetime',
    ];

    protected $attributes = [
        'source_type' => 'url',
    ];

    public function iptvUsers(): HasMany
    {
        return $this->hasMany(IptvUser::class);
    }
}
