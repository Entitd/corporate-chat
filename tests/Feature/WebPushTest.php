<?php

use App\Events\MessageCreated;
use App\Jobs\SendChatPush;
use App\Models\Chat;
use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

beforeEach(function () {
    config()->set('webpush.public_key', 'public-key');
    config()->set('webpush.private_key', 'private-key');
});

function webPushPayload(string $endpoint = 'https://web.push.apple.com/test-device'): array
{
    return ['endpoint' => $endpoint, 'keys' => ['p256dh' => 'B'.str_repeat('a', 86), 'auth' => str_repeat('b', 22)]];
}

test('a guest cannot register or remove a push subscription', function () {
    $this->postJson(route('push-subscriptions.store'), webPushPayload())->assertUnauthorized();
    $this->deleteJson(route('push-subscriptions.destroy'), webPushPayload())->assertUnauthorized();
    $this->assertDatabaseCount('push_subscriptions', 0);
});

test('subscribing stores the current user and repeated subscriptions do not duplicate a device', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $payload = webPushPayload();

    $this->postJson(route('push-subscriptions.store'), $payload + ['user_id' => 999])->assertNoContent();
    $this->postJson(route('push-subscriptions.store'), $payload)->assertNoContent();

    $this->assertDatabaseCount('push_subscriptions', 1);
    $this->assertDatabaseHas('push_subscriptions', ['user_id' => $user->id, 'endpoint' => $payload['endpoint']]);
});

test('a device subscription moves to the newly signed in account', function () {
    $previous = PushSubscription::factory()->create();
    $current = User::factory()->create();

    $this->actingAs($current)->postJson(route('push-subscriptions.store'), webPushPayload($previous->endpoint))->assertNoContent();

    $this->assertDatabaseCount('push_subscriptions', 1);
    $this->assertDatabaseHas('push_subscriptions', ['id' => $previous->id, 'user_id' => $current->id]);
});

test('unconfigured push returns 503 without saving a subscription', function () {
    config()->set('webpush.private_key', null);
    $this->actingAs(User::factory()->create())->postJson(route('push-subscriptions.store'), webPushPayload())->assertStatus(503);
    $this->assertDatabaseCount('push_subscriptions', 0);
});

test('untrusted push endpoints return 422', function (string $endpoint) {
    $this->actingAs(User::factory()->create())
        ->postJson(route('push-subscriptions.store'), webPushPayload($endpoint))
        ->assertUnprocessable()->assertJsonValidationErrors('endpoint');
    $this->assertDatabaseCount('push_subscriptions', 0);
})->with(['http://web.push.apple.com/test', 'https://127.0.0.1/test', 'https://web.push.apple.com.evil.test/test', 'https://web.push.apple.com:8443/test', 'https://user@web.push.apple.com/test']);

test('invalid subscription keys return 422', function (string $key) {
    $payload = webPushPayload();
    $payload['keys'][$key] = 'invalid';
    $this->actingAs(User::factory()->create())->postJson(route('push-subscriptions.store'), $payload)
        ->assertUnprocessable()->assertJsonValidationErrors('keys.'.$key);
    $this->assertDatabaseCount('push_subscriptions', 0);
})->with(['p256dh', 'auth']);

test('a user can remove only their own device subscription', function () {
    $user = User::factory()->create();
    $own = PushSubscription::factory()->create(['user_id' => $user->id]);
    $other = PushSubscription::factory()->create();
    $this->actingAs($user);

    $this->deleteJson(route('push-subscriptions.destroy'), ['endpoint' => $other->endpoint])->assertNoContent();
    $this->assertModelExists($other);
    $this->deleteJson(route('push-subscriptions.destroy'), ['endpoint' => $own->endpoint])->assertNoContent();
    $this->assertModelMissing($own);
});

test('logging out removes this session subscription and preserves other devices', function () {
    $user = User::factory()->create();
    $otherDevice = PushSubscription::factory()->create(['user_id' => $user->id]);
    $this->actingAs($user)->postJson(route('push-subscriptions.store'), webPushPayload())->assertNoContent();

    $this->withCookie(config('session.cookie'), session()->getId())
        ->post(route('logout'))->assertRedirect();

    $this->assertDatabaseMissing('push_subscriptions', ['endpoint' => webPushPayload()['endpoint']]);
    $this->assertModelExists($otherDevice);
});

test('sending a message queues push only for subscribed participants other than its author', function () {
    Queue::fake([SendChatPush::class]);
    $sender = User::factory()->create();
    $recipient = User::factory()->create();
    PushSubscription::factory()->create(['user_id' => $sender->id]);
    $device = PushSubscription::factory()->create(['user_id' => $recipient->id]);
    PushSubscription::factory()->create();
    $chat = Chat::create(['type' => 'group', 'name' => 'Команда']);
    $chat->users()->attach([$sender->id, $recipient->id]);

    $this->actingAs($sender);
    Livewire::test('pages::chat')->call('selectChat', $chat->id)->call('sendMessage', 'Новое сообщение');

    Queue::assertPushed(SendChatPush::class, fn (SendChatPush $job): bool => $job->subscriptionId === $device->id && $job->recipientId === $recipient->id && $job->messageId === $chat->messages()->firstOrFail()->id);
    Queue::assertPushed(SendChatPush::class, 1);
});

test('push is not queued when its configuration is missing', function () {
    Queue::fake([SendChatPush::class]);
    config()->set('webpush.public_key', null);
    $sender = User::factory()->create();
    $device = PushSubscription::factory()->create();
    $chat = Chat::create(['type' => 'direct']);
    $chat->users()->attach([$sender->id, $device->user_id]);
    $message = $chat->messages()->create(['user_id' => $sender->id, 'body' => 'Текст']);

    MessageCreated::dispatch($chat->id, $message->id, [$device->user_id]);

    Queue::assertNotPushed(SendChatPush::class);
});

function pushJobFixture(): array
{
    $sender = User::factory()->create();
    $device = PushSubscription::factory()->create();
    $chat = Chat::create(['type' => 'direct']);
    $chat->users()->attach([$sender->id, $device->user_id]);
    $message = $chat->messages()->create(['user_id' => $sender->id, 'body' => 'Личное сообщение']);

    return [$device, $chat, $message, new SendChatPush($message->id, $device->id, $device->user_id)];
}

test('push delivery includes a chat link and does not expose private message text', function () {
    [$device, $chat, $message, $job] = pushJobFixture();
    $report = Mockery::mock(MessageSentReport::class);
    $report->shouldReceive('isSubscriptionExpired')->once()->andReturnFalse();
    $report->shouldReceive('isSuccess')->once()->andReturnTrue();
    $sender = Mockery::mock(WebPush::class);
    $sender->shouldReceive('sendOneNotification')->once()->withArgs(function (Subscription $subscription, string $payload, array $options) use ($device, $chat): bool {
        expect($subscription->getEndpoint())->toBe($device->endpoint);
        expect(json_decode($payload, true))->toMatchArray([
            'title' => 'Новое сообщение', 'body' => 'Откройте чат, чтобы прочитать сообщение.', 'url' => '/chat?chat='.$chat->id,
        ]);
        expect($options)->toBe(['TTL' => 3600]);

        return true;
    })->andReturn($report);

    $job->handle($sender);
    $this->assertModelExists($device);
});

test('expired push subscriptions are removed', function () {
    [$device, $chat, $message, $job] = pushJobFixture();
    $report = Mockery::mock(MessageSentReport::class);
    $report->shouldReceive('isSubscriptionExpired')->once()->andReturnTrue();
    $sender = Mockery::mock(WebPush::class);
    $sender->shouldReceive('sendOneNotification')->once()->andReturn($report);

    $job->handle($sender);

    $this->assertModelMissing($device);
});

test('failed push delivery raises an error so the queue can retry', function () {
    [$device, $chat, $message, $job] = pushJobFixture();
    $report = Mockery::mock(MessageSentReport::class);
    $report->shouldReceive('isSubscriptionExpired')->once()->andReturnFalse();
    $report->shouldReceive('isSuccess')->once()->andReturnFalse();
    $report->shouldReceive('getResponse')->once()->andReturnNull();
    $sender = Mockery::mock(WebPush::class);
    $sender->shouldReceive('sendOneNotification')->once()->andReturn($report);

    expect(fn () => $job->handle($sender))->toThrow(RuntimeException::class);
    $this->assertModelExists($device);
});

test('queued delivery skips removed recipients and devices assigned to another account', function (string $change) {
    [$device, $chat, $message, $job] = pushJobFixture();
    if ($change === 'membership') {
        $chat->users()->detach($device->user_id);
    } elseif ($change === 'device') {
        $device->update(['user_id' => User::factory()->create()->id]);
    } elseif ($change === 'message') {
        $message->delete();
    } else {
        $device->delete();
    }
    $sender = Mockery::mock(WebPush::class);
    $sender->shouldNotReceive('sendOneNotification');

    $job->handle($sender);
})->with(['membership', 'device', 'message', 'subscription']);

test('a malformed subscription returns 422 without writing a device', function (array $payload, string $field) {
    $this->actingAs(User::factory()->create())->postJson(route('push-subscriptions.store'), $payload)
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('push_subscriptions', 0);
})->with([
    'missing endpoint' => [[], 'endpoint'],
    'endpoint array' => [['endpoint' => ['invalid'], 'keys' => ['p256dh' => 'B'.str_repeat('a', 86), 'auth' => str_repeat('b', 22)]], 'endpoint'],
    'missing keys' => [['endpoint' => 'https://web.push.apple.com/device'], 'keys'],
]);
