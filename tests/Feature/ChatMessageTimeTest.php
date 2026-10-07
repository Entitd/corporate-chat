<?php

use App\Models\Chat;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

test('a message shows its sending time even after it is updated', function () {
    $sender = User::factory()->create();
    $recipient = User::factory()->create();
    $chat = Chat::create(['type' => 'direct']);
    $chat->users()->attach([$sender->id, $recipient->id]);
    $this->travelTo(Carbon::parse('2026-01-01 14:35:00', 'UTC'));
    $message = $chat->messages()->create(['user_id' => $sender->id, 'body' => 'Текст сообщения']);
    $this->travelTo(Carbon::parse('2026-01-01 18:42:00', 'UTC'));
    $message->update(['body' => 'Обновлённый текст']);
    $this->actingAs($recipient);

    $component = Livewire::test('pages::chat')
        ->call('selectChat', $chat->id)
        ->assertSee('Обновлённый текст')
        ->assertSee('>14:35</time>', false)
        ->assertDontSee('>18:42</time>', false)
        ->assertSee('datetime="2026-01-01T14:35:00.000000Z"', false);

    expect(collect($component->instance()->chats)->firstWhere('id', $chat->id)['last_message']['sent_at'])
        ->toBe('2026-01-01T14:35:00+00:00');
});
