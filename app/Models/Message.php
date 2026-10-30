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

    public const REACTION_EMOJIS = ['👍', '❤️', '😂', '🔥', '🎉', '😮', '😢', '👎'];

    protected $fillable = [
        'chat_id',
        'user_id',
        'body',
        'parent_id',
        'forwarded_message_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'chat_id' => 'integer',
            'user_id' => 'integer',
            'parent_id' => 'integer',
            'forwarded_message_id' => 'integer',
            'body' => 'encrypted',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<Chat, $this> */
    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }

    /** @return BelongsTo<Message, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return BelongsTo<Message, $this> */
    public function forwardedMessage(): BelongsTo
    {
        return $this->belongsTo(self::class, 'forwarded_message_id');
    }

    /** @return HasMany<Attachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function reactors(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'message_reactions')->withPivot('emoji');
    }

    /** @return BelongsToMany<User, $this> */
    public function mentions(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'message_mentions')->orderBy('message_mentions.id');
    }
}
