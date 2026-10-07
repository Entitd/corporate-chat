<?php

use App\Models\Chat;
use App\Models\User;
use Livewire\Livewire;

test('a dialog shows only incoming unread messages and opening it persists the read position', function () {
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
        'last_read_message_id' => $lastUnread->id,
    ]);
    expect(collect($component->instance()->chats)->firstWhere('id', $unreadChat->id)['unread'])->toBe(0);

    Livewire::test('pages::chat')->assertDontSee('data-test="chat-unread-count"', false);

    $unreadChat->messages()->create(['user_id' => $sender->id, 'body' => 'После прочтения']);

    expect(collect(Livewire::test('pages::chat')->instance()->chats)->firstWhere('id', $unreadChat->id)['unread'])->toBe(1);
});

test('a new message increments an unopened dialog while the active dialog stays read', function () {
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

    expect(collect($component->instance()->chats)->firstWhere('id', $activeChat->id)['unread'])->toBe(0);
    $this->assertDatabaseHas('chat_users', [
        'chat_id' => $activeChat->id,
        'user_id' => $recipient->id,
        'last_read_message_id' => $activeMessage->id,
    ]);

    $otherMessage = $unopenedChat->messages()->create(['user_id' => $sender->id, 'body' => 'В другом']);
    $component->dispatch('echo-private:users.'.$recipient->id.',.chat.message.created', [
        'chatId' => $unopenedChat->id,
        'messageId' => $otherMessage->id,
    ]);

    expect(collect($component->instance()->chats)->firstWhere('id', $unopenedChat->id)['unread'])->toBe(1);
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

    $component->call('markAllAsRead');

    expect($component->instance()->unreadTotal)->toBe(0);
    expect(Livewire::test('pages::chat')->instance()->unreadTotal)->toBe(0);
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
