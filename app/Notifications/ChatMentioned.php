<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ChatMentioned extends Notification
{
    use Queueable;

    public function __construct(
        public readonly int $chatId,
        public readonly int $messageId,
        public readonly string $chatName,
        public readonly string $authorName,
        public readonly string $excerpt,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, int|string> */
    public function toArray(object $notifiable): array
    {
        return [
            'chat_id' => $this->chatId,
            'message_id' => $this->messageId,
            'chat_name' => $this->chatName,
            'author_name' => $this->authorName,
            'excerpt' => $this->excerpt,
        ];
    }
}
