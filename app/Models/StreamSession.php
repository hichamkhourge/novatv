<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StreamSession extends Model
{
    protected $fillable = [
        'iptv_user_id',
        'ip_address',
        'user_agent',
        'stream_id',
        'started_at',
        'last_seen_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function iptvUser(): BelongsTo
    {
        return $this->belongsTo(IptvUser::class);
    }
}
