<?php

use App\Models\Chat;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('the sender can see which group members have read their message', function () {
    $this->freezeTime();
    $readAt = now()->startOfSecond()->toIso8601String();
    $sender = User::factory()->create(['name' => 'Алиса']);
    $reader = User::factory()->create(['name' => 'Борис']);
    $unreadMember = User::factory()->create(['name' => 'Вера']);
    $chat = Chat::create(['type' => 'group', 'name' => 'Команда']);
    $chat->users()->attach([$sender->id, $reader->id, $unreadMember->id]);
    $message = $chat->messages()->create(['user_id' => $sender->id, 'body' => 'План готов']);
    $this->actingAs($reader);
    Livewire::test('pages::chat')->call('selectChat', $chat->id)->call('markOpenChatAsRead', $message->id)->assertSee('План готов');

    $this->actingAs($sender);
    $component = Livewire::test('pages::chat')
        ->call('selectChat', $chat->id)
        ->assertSee('data-test="show-message-readers"', false)
        ->call('showMessageReaders', $message->id)
        ->assertSet('showMessageReadersModal', true)
        ->assertSee('data-test="message-readers-modal"', false)
        ->assertSee('Прочитали · 1')
        ->assertSee('datetime="'.$readAt.'"', false)
        ->assertSee('Ещё не прочитали · 1');

    expect($component->instance()->messageReaders)->toBe([
        'read' => [['id' => $reader->id, 'name' => 'Борис', 'read_at' => $readAt]],
        'unread' => [['id' => $unreadMember->id, 'name' => 'Вера']],
    ]);

    $this->actingAs($unreadMember);
    $this->travel(5)->minutes();
    $secondReadAt = now()->startOfSecond()->toIso8601String();
    Livewire::test('pages::chat')->call('selectChat', $chat->id)->call('markOpenChatAsRead', $message->id)->assertSee('План готов');

    $this->actingAs($sender);
    $component->call('refreshReadStatus', ['chatId' => $chat->id])
        ->assertSee('Прочитали · 2')
        ->assertSee('datetime="'.$secondReadAt.'"', false);
    expect($component->instance()->messageReaders['unread'])->toBe([]);
});

test('reading further records each incoming message once and preserves earlier read times', function () {
    $this->freezeTime();
    $sender = User::factory()->create();
    $reader = User::factory()->create();
    $chat = Chat::create(['type' => 'group', 'name' => 'Команда']);
    $chat->users()->attach([$sender->id, $reader->id]);
    $first = $chat->messages()->create(['user_id' => $sender->id, 'body' => 'Первое']);
    $own = $chat->messages()->create(['user_id' => $reader->id, 'body' => 'Моё']);
    $second = $chat->messages()->create(['user_id' => $sender->id, 'body' => 'Второе']);
    $third = $chat->messages()->create(['user_id' => $sender->id, 'body' => 'Третье']);
    $firstReadAt = now()->toDateTimeString();
    $this->actingAs($reader);

    $component = Livewire::test('pages::chat')->call('selectChat', $chat->id);
    $this->assertDatabaseCount('message_reads', 0);
    $component->call('markOpenChatAsRead', $first->id);
    $this->assertDatabaseCount('message_reads', 1);

    $this->travel(10)->minutes();
    $laterReadAt = now()->toDateTimeString();
    $component->call('markOpenChatAsRead', $first->id)
        ->call('markOpenChatAsRead', $third->id)
        ->call('markOpenChatAsRead', $second->id);

    $this->assertDatabaseCount('message_reads', 3);
    $this->assertDatabaseHas('message_reads', ['message_id' => $first->id, 'user_id' => $reader->id, 'read_at' => $firstReadAt]);
    foreach ([$second, $third] as $message) {
        $this->assertDatabaseHas('message_reads', ['message_id' => $message->id, 'user_id' => $reader->id, 'read_at' => $laterReadAt]);
    }
    $this->assertDatabaseMissing('message_reads', ['message_id' => $own->id]);
});

test('historical reads keep an unknown timestamp after newer messages are read', function () {
    $sender = User::factory()->create();
    $reader = User::factory()->create();
    $chat = Chat::create(['type' => 'group', 'name' => 'Команда']);
    $chat->users()->attach([$sender->id, $reader->id]);
    $old = $chat->messages()->create(['user_id' => $sender->id, 'body' => 'Старое']);
    DB::table('chat_users')->where('chat_id', $chat->id)->where('user_id', $reader->id)->update(['last_read_message_id' => $old->id]);
    $new = $chat->messages()->create(['user_id' => $sender->id, 'body' => 'Новое']);
    $this->actingAs($reader);
    Livewire::test('pages::chat')->call('selectChat', $chat->id)->call('markOpenChatAsRead', $new->id);

    $this->actingAs($sender);
    $component = Livewire::test('pages::chat')->call('selectChat', $chat->id)
        ->call('showMessageReaders', $old->id)
        ->assertSee('Время неизвестно');
    expect($component->instance()->messageReaders['read'][0]['read_at'])->toBeNull();
    $this->assertDatabaseMissing('message_reads', ['message_id' => $old->id]);
    $component->call('showMessageReaders', $new->id)->assertSee('data-test="message-read-at"', false);
});

test('mark all as read records group receipts while direct chats keep their existing behavior', function () {
    $sender = User::factory()->create();
    $reader = User::factory()->create();
    $groupMessages = [];
    foreach (['group', 'group', 'direct'] as $type) {
        $chat = Chat::create(['type' => $type, 'name' => 'Чат']);
        $chat->users()->attach([$sender->id, $reader->id]);
        $message = $chat->messages()->create(['user_id' => $sender->id, 'body' => 'Сообщение']);
        if ($type === 'group') {
            $groupMessages[] = $message->id;
        }
    }
    $this->actingAs($reader);
    Livewire::test('pages::chat')->call('markAllAsRead');

    expect(DB::table('message_reads')->orderBy('message_id')->pluck('message_id')->all())->toBe($groupMessages);
    expect(DB::table('message_reads')->pluck('user_id')->unique()->all())->toBe([$reader->id]);
});

test('message readers are limited to the sender of a message in the active group', function () {
    $sender = User::factory()->create();
    $recipient = User::factory()->create();
    $group = Chat::create(['type' => 'group', 'name' => 'Команда']);
    $group->users()->attach([$sender->id, $recipient->id]);
    $message = $group->messages()->create(['user_id' => $sender->id, 'body' => 'Секрет']);
    $this->actingAs($recipient);

    Livewire::test('pages::chat')->call('selectChat', $group->id)->call('showMessageReaders', $message->id)->assertForbidden();

    $direct = Chat::create(['type' => 'direct']);
    $direct->users()->attach([$sender->id, $recipient->id]);
    $directMessage = $direct->messages()->create(['user_id' => $sender->id, 'body' => 'Личное']);
    $this->actingAs($sender);

    Livewire::test('pages::chat')->call('selectChat', $group->id)->call('showMessageReaders', $directMessage->id)->assertNotFound();
    Livewire::test('pages::chat')
        ->call('selectChat', $direct->id)
        ->call('showMessageReaders', $directMessage->id)
        ->assertForbidden();
});
