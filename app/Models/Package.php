<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Package extends Model
{
    protected $fillable = [
        'name',
        'description',
        'max_connections',
        'duration_days',
        'price',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'price' => 'decimal:2',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(IptvUser::class);
    }

    public function channelGroups(): BelongsToMany
    {
        return $this->belongsToMany(ChannelGroup::class, 'package_channel_group');
    }
}
