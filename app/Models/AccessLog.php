<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccessLog extends Model
{
    protected $fillable = [
        'account_id',
        'channel_id',
        'ip_address',
        'username',
        'action',
        'status',
        'user_agent',
    ];

    protected $casts = [];

    /** IPTV account (nullable) */
    public function account(): BelongsTo
    {
        return $this->belongsTo(IptvAccount::class, 'account_id');
    }

    /** Channel (nullable) */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }
}
