<?php

use App\Events\ChatRead;
use App\Models\Chat;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

test('opening a dialog keeps new messages unread until the reader reaches them', function () {
    $recipient = User::factory()->create();
    $sender = User::factory()->create();
    $openChat = Chat::create(['type' => 'direct']);
    $openChat->users()->attach([$recipient->id, $sender->id]);
    $unreadChat = Chat::create(['type' => 'group', 'name' => 'Команда']);
    $unreadChat->users()->attach([$recipient->id, $sender->id]);
    $unreadChat->messages()->create(['user_id' => $sender->id, 'body' => 'Первое']);
    $unreadChat->messages()->create(['user_id' => $recipient->id, 'body' => 'Моё']);
    $lastUnread = $unreadChat->messages()->create(['user_id' => $sender->id, 'body' => 'Второе']);
    $this->actingAs($recipient);

    $component = Livewire::test('pages::chat')->assertSee('data-test="chat-unread-count"', false);

    expect(collect($component->instance()->chats)->firstWhere('id', $unreadChat->id)['unread'])->toBe(2);
    expect($component->instance()->unreadTotal)->toBe(2);

    $component->call('selectChat', $unreadChat->id);

    $this->assertDatabaseHas('chat_users', [
        'chat_id' => $unreadChat->id,
        'user_id' => $recipient->id,
        'last_read_message_id' => null,
    ]);
    expect(collect($component->instance()->chats)->firstWhere('id', $unreadChat->id)['unread'])->toBe(2);
    $component->assertSee('data-test="unread-messages-button"', false)
        ->assertSee('data-test="unread-message-divider"', false);

    $component->call('markOpenChatAsRead', $lastUnread->id)
        ->assertDispatched('chat-read-through');

    $this->assertDatabaseHas('chat_users', [
        'chat_id' => $unreadChat->id,
        'user_id' => $recipient->id,
        'last_read_message_id' => $lastUnread->id,
    ]);
    expect(collect($component->instance()->chats)->firstWhere('id', $unreadChat->id)['unread'])->toBe(0);
    $component->assertSee('data-test="unread-message-divider"', false)
        ->assertSee('>Новые сообщения</span>', false);

    $component->call('refreshOpenChat')->assertSee('data-test="unread-message-divider"', false);
    $component->call('selectChat', $openChat->id)->assertDontSee('data-test="unread-message-divider"', false);
    $component->call('selectChat', $unreadChat->id)->assertDontSee('data-test="unread-message-divider"', false);

    Livewire::test('pages::chat')->assertDontSee('data-test="chat-unread-count"', false);

    $unreadChat->messages()->create(['user_id' => $sender->id, 'body' => 'После прочтения']);

    expect(collect(Livewire::test('pages::chat')->instance()->chats)->firstWhere('id', $unreadChat->id)['unread'])->toBe(1);
});

test('the unread divider appears after the last outgoing message and before the first new incoming message', function () {
    $recipient = User::factory()->create();
    $sender = User::factory()->create();
    $chat = Chat::create(['type' => 'direct']);
    $chat->users()->attach([$recipient->id, $sender->id]);
    $lastRead = $chat->messages()->create(['user_id' => $sender->id, 'body' => 'Прочитано']);
    $chat->users()->updateExistingPivot($recipient->id, ['last_read_message_id' => $lastRead->id]);
    $outgoing = $chat->messages()->create(['user_id' => $recipient->id, 'body' => 'Мой ответ']);
    $incoming = $chat->messages()->create(['user_id' => $sender->id, 'body' => 'Новый ответ']);
    $this->actingAs($recipient);

    $component = Livewire::test('pages::chat')->call('selectChat', $chat->id);
    $html = $component->html();

    expect(strpos($html, 'data-chat-message-id="'.$outgoing->id.'"'))
        ->toBeLessThan(strpos($html, 'data-test="unread-message-divider"'));
    expect(strpos($html, 'data-test="unread-message-divider"'))
        ->toBeLessThan(strpos($html, 'data-chat-message-id="'.$incoming->id.'"'));
    $component->assertSee('Новое сообщение');
});

test('a new message stays unread in the active dialog until the reader reaches it', function () {
    $recipient = User::factory()->create();
    $sender = User::factory()->create();
    $activeChat = Chat::create(['type' => 'group', 'name' => 'Открытый']);
    $unopenedChat = Chat::create(['type' => 'group', 'name' => 'Другой']);
    $activeChat->users()->attach([$recipient->id, $sender->id]);
    $unopenedChat->users()->attach([$recipient->id, $sender->id]);
    $this->actingAs($recipient);
    $component = Livewire::test('pages::chat')->call('selectChat', $activeChat->id);
    $activeMessage = $activeChat->messages()->create(['user_id' => $sender->id, 'body' => 'В открытом']);

    $component->dispatch('echo-private:users.'.$recipient->id.',.chat.message.created', [
        'chatId' => $activeChat->id,
        'messageId' => $activeMessage->id,
    ]);

    expect(collect($component->instance()->chats)->firstWhere('id', $activeChat->id)['unread'])->toBe(1);
    $component->assertSee('data-test="unread-message-divider"', false)
        ->assertSee('>Новое сообщение</span>', false);
    $this->assertDatabaseHas('chat_users', [
        'chat_id' => $activeChat->id,
        'user_id' => $recipient->id,
        'last_read_message_id' => null,
    ]);

    $component->call('markOpenChatAsRead', $activeMessage->id);

    expect(collect($component->instance()->chats)->firstWhere('id', $activeChat->id)['unread'])->toBe(0);
    $component->assertSee('data-test="unread-message-divider"', false)
        ->assertSee('>Новое сообщение</span>', false);

    $otherMessage = $unopenedChat->messages()->create(['user_id' => $sender->id, 'body' => 'В другом']);
    $component->dispatch('echo-private:users.'.$recipient->id.',.chat.message.created', [
        'chatId' => $unopenedChat->id,
        'messageId' => $otherMessage->id,
    ]);

    expect(collect($component->instance()->chats)->firstWhere('id', $unopenedChat->id)['unread'])->toBe(1);
});

test('opening a long unread dialog includes the last read message', function () {
    $recipient = User::factory()->create();
    $sender = User::factory()->create();
    $chat = Chat::create(['type' => 'direct']);
    $chat->users()->attach([$recipient->id, $sender->id]);
    $lastRead = $chat->messages()->create(['user_id' => $sender->id, 'body' => 'Последнее прочитанное']);
    $chat->users()->updateExistingPivot($recipient->id, ['last_read_message_id' => $lastRead->id]);

    foreach (range(1, 35) as $number) {
        $chat->messages()->create(['user_id' => $sender->id, 'body' => "Новое {$number}"]);
    }

    $this->actingAs($recipient);

    $component = Livewire::test('pages::chat')->call('selectChat', $chat->id);

    expect(array_column($component->instance()->messages['data'], 'id'))->toContain($lastRead->id);
    $component->assertSee('data-last-read-message-id="'.$lastRead->id.'"', false)
        ->assertSee('data-test="unread-messages-button"', false);
    $this->assertDatabaseHas('chat_users', [
        'chat_id' => $chat->id,
        'user_id' => $recipient->id,
        'last_read_message_id' => $lastRead->id,
    ]);
});

test('reading a displayed message does not mark a later arrival as read', function () {
    $recipient = User::factory()->create();
    $sender = User::factory()->create();
    $chat = Chat::create(['type' => 'direct']);
    $chat->users()->attach([$recipient->id, $sender->id]);
    $displayedMessage = $chat->messages()->create(['user_id' => $sender->id, 'body' => 'На экране']);
    $this->actingAs($recipient);
    $component = Livewire::test('pages::chat')->call('selectChat', $chat->id);
    $chat->messages()->create(['user_id' => $sender->id, 'body' => 'Пришло позже']);

    $component->call('markOpenChatAsRead', $displayedMessage->id);

    $this->assertDatabaseHas('chat_users', [
        'chat_id' => $chat->id,
        'user_id' => $recipient->id,
        'last_read_message_id' => $displayedMessage->id,
    ]);
    expect(collect($component->instance()->chats)->firstWhere('id', $chat->id)['unread'])->toBe(1);
});

test('a message from another dialog cannot advance the read position', function () {
    $recipient = User::factory()->create();
    $sender = User::factory()->create();
    $openChat = Chat::create(['type' => 'direct']);
    $otherChat = Chat::create(['type' => 'direct']);
    $openChat->users()->attach([$recipient->id, $sender->id]);
    $otherChat->users()->attach([$recipient->id, $sender->id]);
    $message = $otherChat->messages()->create(['user_id' => $sender->id, 'body' => 'Другой чат']);
    $this->actingAs($recipient);

    Livewire::test('pages::chat')
        ->call('selectChat', $openChat->id)
        ->call('markOpenChatAsRead', $message->id)
        ->assertNotFound();

    $this->assertDatabaseHas('chat_users', [
        'chat_id' => $openChat->id,
        'user_id' => $recipient->id,
        'last_read_message_id' => null,
    ]);
});

test('marking all dialogs as read clears their counters for later visits', function () {
    $recipient = User::factory()->create();
    $sender = User::factory()->create();
    $firstChat = Chat::create(['type' => 'group', 'name' => 'Первый']);
    $secondChat = Chat::create(['type' => 'group', 'name' => 'Второй']);
    $firstChat->users()->attach([$recipient->id, $sender->id]);
    $secondChat->users()->attach([$recipient->id, $sender->id]);
    $firstChat->messages()->create(['user_id' => $sender->id, 'body' => 'В первом']);
    $secondChat->messages()->create(['user_id' => $sender->id, 'body' => 'Во втором']);
    $this->actingAs($recipient);
    $component = Livewire::test('pages::chat');

    expect($component->instance()->unreadTotal)->toBe(2);

    Event::fake([ChatRead::class]);
    $component->call('markAllAsRead');

    expect($component->instance()->unreadTotal)->toBe(0);
    expect(Livewire::test('pages::chat')->instance()->unreadTotal)->toBe(0);
    Event::assertDispatched(ChatRead::class, 2);
});

test('a newly invited colleague starts with the existing conversation read', function () {
    $owner = User::factory()->create();
    $invitee = User::factory()->create();
    $chat = Chat::create(['type' => 'group', 'name' => 'Проект']);
    $chat->users()->attach($owner->id);
    $previousMessage = $chat->messages()->create(['user_id' => $owner->id, 'body' => 'До приглашения']);
    $this->actingAs($owner);

    Livewire::test('pages::chat')
        ->call('openInviteModal', $chat->id)
        ->set('selectedInviteeId', $invitee->id)
        ->call('inviteColleague')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('chat_users', [
        'chat_id' => $chat->id,
        'user_id' => $invitee->id,
        'last_read_message_id' => $previousMessage->id,
    ]);
});
