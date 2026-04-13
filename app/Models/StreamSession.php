<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StreamSession extends Model
{
    protected $fillable = [
        'account_id',
        'channel_id',
        'ip_address',
        'started_at',
        'last_seen_at',
    ];

    protected $casts = [
        'started_at'   => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    /** Account that owns this session */
    public function account(): BelongsTo
    {
        return $this->belongsTo(IptvAccount::class, 'account_id');
    }

    /** Channel being streamed */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }
}
