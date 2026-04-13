<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ChannelGroup extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (ChannelGroup $group) {
            if (empty($group->slug)) {
                $group->slug = Str::slug($group->name);
            }
        });
    }

    /** Channels in this group */
    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class);
    }

    /** Accounts with specific access to this group */
    public function iptvAccounts(): BelongsToMany
    {
        return $this->belongsToMany(IptvAccount::class, 'account_channel_groups', 'channel_group_id', 'account_id');
    }

    /** Scope to only active groups */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
