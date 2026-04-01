<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConnectionLog extends Model
{
    public $timestamps = false; // Only created_at, no updated_at

    protected $fillable = [
        'iptv_user_id',
        'channel_id',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    /**
     * IPTV user who made the connection
     */
    public function iptvUser(): BelongsTo
    {
        return $this->belongsTo(IptvUser::class);
    }

    /**
     * Channel that was accessed
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }
}
