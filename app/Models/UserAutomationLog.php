<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserAutomationLog extends Model
{
    protected $fillable = [
        'iptv_user_id',
        'm3u_source_id',
        'status',
        'output',
        'error',
        'duration_seconds',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function iptvUser(): BelongsTo
    {
        return $this->belongsTo(IptvUser::class, 'iptv_user_id');
    }

    public function m3uSource(): BelongsTo
    {
        return $this->belongsTo(M3uSource::class);
    }
}
