<?php

use App\Models\Chat;
use App\Models\User;
use Livewire\Livewire;

test('all colleagues appear before any conversations are created', function () {
    $user = User::factory()->create(['name' => 'Current Employee']);
    User::factory()->create(['name' => 'Boris']);
    User::factory()->create(['name' => 'Alice']);
    $this->actingAs($user);

    $component = Livewire::test('pages::chat')
        ->assertSee('Alice')
        ->assertSee('Boris')
        ->assertSet('activeChatId', 0);

    expect(array_column($component->instance()->chats, 'name'))->toBe(['Alice', 'Boris']);
    expect($component->instance()->directCount)->toBe(2);
    expect($component->instance()->unreadTotal)->toBe(0);
    $this->assertDatabaseCount('chats', 0);
});

test('existing conversations retain their history and colleagues appear only once', function () {
    $user = User::factory()->create();
    $colleague = User::factory()->create(['name' => 'Boris']);
    User::factory()->create(['name' => 'Alice']);
    $chat = Chat::create(['type' => 'direct']);
    $chat->users()->attach([$user->id, $colleague->id]);
    $chat->messages()->create(['user_id' => $colleague->id, 'body' => 'Existing conversation']);
    $this->actingAs($user);

    $component = Livewire::test('pages::chat')->assertSee('Existing conversation');

    expect(array_column($component->instance()->chats, 'name'))->toBe(['Boris', 'Alice']);
    expect($component->instance()->chats[0]['id'])->toBe($chat->id);
    expect($component->instance()->chats[0]['unread'])->toBe(1);
});

test('colleagues without conversations can be searched and filtered', function () {
    $user = User::factory()->create();
    User::factory()->create(['name' => 'Анна']);
    User::factory()->create(['name' => 'Борис']);
    $group = Chat::create(['type' => 'group', 'name' => 'Команда']);
    $group->users()->attach($user);
    $this->actingAs($user);

    $component = Livewire::test('pages::chat')->call('setFilter', 'direct')->set('search', 'АННА');

    expect(array_column($component->instance()->chats, 'name'))->toBe(['Анна']);

    $component->set('search', '')->call('setFilter', 'group');

    expect(array_column($component->instance()->chats, 'name'))->toBe(['Команда']);
});

test('selecting a colleague opens a direct conversation and reuses it on subsequent selections', function () {
    $user = User::factory()->create();
    $colleague = User::factory()->create();
    $this->actingAs($user);

    $component = Livewire::test('pages::chat')
        ->set('newChatMode', 'group')
        ->call('selectChat', -$colleague->id)
        ->assertSet('showChatList', false);

    $chat = Chat::sole();
    $component->assertSet('activeChatId', $chat->id);
    expect($chat->type)->toBe('direct');
    expect($chat->users()->pluck('users.id')->sort()->values()->all())
        ->toBe(collect([$user->id, $colleague->id])->sort()->values()->all());

    $component->call('selectChat', -$colleague->id)->assertSet('activeChatId', $chat->id);

    expect($component->instance()->chats)->toHaveCount(1);
    $this->assertDatabaseCount('chats', 1);
});

test('selecting yourself or a missing colleague cannot create a conversation', function (string $target) {
    $user = User::factory()->create();
    $this->actingAs($user);
    $targetId = $target === 'self' ? $user->id : $user->id + 1;

    Livewire::test('pages::chat')->call('selectChat', -$targetId)->assertNotFound();

    $this->assertDatabaseCount('chats', 0);
})->with(['self', 'missing']);
