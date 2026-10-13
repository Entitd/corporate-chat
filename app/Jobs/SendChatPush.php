<?php

namespace App\Jobs;

use App\Models\Message;
use App\Models\PushSubscription;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class SendChatPush implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 45;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(public int $messageId, public int $subscriptionId, public int $recipientId) {}

    public function handle(WebPush $webPush): void
    {
        $subscription = PushSubscription::where('user_id', $this->recipientId)->find($this->subscriptionId);
        $message = Message::find($this->messageId);

        if (! $subscription || ! $message || $message->user_id === $this->recipientId
            || ! $message->chat->users()->whereKey($this->recipientId)->exists()) {
            return;
        }

        $payload = json_encode([
            'title' => __('Новое сообщение'),
            'body' => __('Откройте чат, чтобы прочитать сообщение.'),
            'url' => route('chat.index', ['chat' => $message->chat_id], false),
            'tag' => 'message-'.$message->id,
        ], JSON_THROW_ON_ERROR);

        $report = $webPush->sendOneNotification(Subscription::create([
            'endpoint' => $subscription->endpoint,
            'keys' => ['p256dh' => $subscription->public_key, 'auth' => $subscription->auth_token],
        ]), $payload, ['TTL' => 3600]);

        if ($report->isSubscriptionExpired()) {
            $subscription->delete();
        } elseif (! $report->isSuccess()) {
            throw new \RuntimeException('Web Push delivery failed: HTTP '.($report->getResponse()?->getStatusCode() ?? 'unavailable'));
        }
    }
}
