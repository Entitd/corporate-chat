<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class MessageCreated implements ShouldBroadcastNow, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable;

    /** @param array<int, int> $recipientIds */
    public function __construct(
        public readonly int $chatId,
        public readonly int $messageId,
        private readonly array $recipientIds,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return array_map(
            fn (int $userId): PrivateChannel => new PrivateChannel('users.'.$userId),
            $this->recipientIds,
        );
    }

    public function broadcastAs(): string
    {
        return 'chat.message.created';
    }

    /** @return array{chatId: int, messageId: int} */
    public function broadcastWith(): array
    {
        return ['chatId' => $this->chatId, 'messageId' => $this->messageId];
    }
}
