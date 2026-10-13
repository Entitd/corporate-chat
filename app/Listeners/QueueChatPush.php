<?php

namespace App\Listeners;

use App\Events\MessageCreated;
use App\Jobs\SendChatPush;
use App\Models\Message;
use App\Models\PushSubscription;

class QueueChatPush
{
    public function handle(MessageCreated $event): void
    {
        if (blank(config('webpush.public_key')) || blank(config('webpush.private_key'))) {
            return;
        }

        $message = Message::where('chat_id', $event->chatId)->find($event->messageId);

        if (! $message) {
            return;
        }

        PushSubscription::where('user_id', '!=', $message->user_id)
            ->whereIn('user_id', $message->chat->users()->select('users.id'))
            ->eachById(function (PushSubscription $subscription) use ($message): void {
                SendChatPush::dispatch($message->id, $subscription->id, $subscription->user_id)->afterCommit();
            });
    }
}
