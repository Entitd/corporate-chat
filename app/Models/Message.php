<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Message extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'chat_id',
        'user_id',
        'body',
        'parent_id',
        'forwarded_message_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<Chat, $this> */
    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }

    /** @return HasMany<Attachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function mentions(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'message_mentions');
    }
}
