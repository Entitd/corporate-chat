<?php

namespace App\Models;

use Database\Factories\PushSubscriptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PushSubscription extends Model
{
    /** @use HasFactory<PushSubscriptionFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'endpoint', 'endpoint_hash', 'public_key', 'auth_token', 'session_hash'];

    protected $hidden = ['endpoint', 'public_key', 'auth_token', 'session_hash'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
