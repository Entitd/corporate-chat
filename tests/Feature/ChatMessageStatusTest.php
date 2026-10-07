<?php

use App\Models\Chat;
use App\Models\User;
use Livewire\Livewire;

test('an outgoing direct message changes from sent to read when the recipient opens the chat', function () {
    $sender = User::factory()->create();
    $recipient = User::factory()->create();
    $chat = Chat::create(['type' => 'direct']);
    $chat->users()->attach([$sender->id, $recipient->id]);
    $this->actingAs($sender);

    $senderChat = Livewire::test('pages::chat')
        ->call('sendMessage', 'Привет')
        ->assertSee('data-message-status="sent"', false)
        ->assertDontSee('data-message-status="read"', false);

    $this->actingAs($recipient);
    Livewire::test('pages::chat')->assertSee('Привет');

    $this->actingAs($sender);
    $senderChat->call('$refresh')
        ->assertSee('data-message-status="read"', false)
        ->assertDontSee('data-message-status="sent"', false);
});

test('an outgoing group message is read only after every other member opens the chat', function () {
    $sender = User::factory()->create();
    $firstRecipient = User::factory()->create();
    $secondRecipient = User::factory()->create();
    $chat = Chat::create(['type' => 'group', 'name' => 'Команда']);
    $chat->users()->attach([$sender->id, $firstRecipient->id, $secondRecipient->id]);
    $this->actingAs($sender);
    Livewire::test('pages::chat')->call('sendMessage', 'Обновление');

    $this->actingAs($firstRecipient);
    Livewire::test('pages::chat')->assertSee('Обновление');

    $this->actingAs($sender);
    Livewire::test('pages::chat')->assertSee('data-message-status="sent"', false);

    $this->actingAs($secondRecipient);
    Livewire::test('pages::chat')->assertSee('Обновление');

    $this->actingAs($sender);
    Livewire::test('pages::chat')->assertSee('data-message-status="read"', false);
});
