<?php

use App\Models\Chat;
use App\Models\User;
use Livewire\Livewire;

test('a named group is created with the selected colleagues and opened', function () {
    $user = User::factory()->create();
    $firstColleague = User::factory()->create(['name' => 'Алексей']);
    $secondColleague = User::factory()->create(['name' => 'Алексей']);

    $this->actingAs($user);

    $component = Livewire::test('pages::chat')
        ->call('setNewChatMode', 'group')
        ->set('groupName', '  Команда проекта  ')
        ->set('selectedColleagues', [(string) $firstColleague->id, (string) $secondColleague->id])
        ->call('createChat')
        ->assertHasNoErrors()
        ->assertSet('showChatList', false)
        ->assertSet('showNewChatModal', false);

    $chat = Chat::query()->sole();

    expect($chat->name)->toBe('Команда проекта');
    expect($chat->type)->toBe('group');
    expect($component->get('activeChatId'))->toBe($chat->id);
    $this->assertDatabaseHas('chat_users', ['chat_id' => $chat->id, 'user_id' => $user->id, 'role' => 'admin']);
    $this->assertDatabaseHas('chat_users', ['chat_id' => $chat->id, 'user_id' => $firstColleague->id]);
    $this->assertDatabaseHas('chat_users', ['chat_id' => $chat->id, 'user_id' => $secondColleague->id]);
});

test('group creation requires a name and real colleagues other than the creator', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test('pages::chat')
        ->call('setNewChatMode', 'group')
        ->set('groupName', '   ')
        ->set('selectedColleagues', [$user->id])
        ->call('createChat')
        ->assertHasErrors(['groupName' => 'required', 'selectedColleagues.0' => 'not_in']);

    expect(Chat::query()->count())->toBe(0);
});

test('a direct chat is opened without creating a duplicate', function () {
    $user = User::factory()->create();
    $colleague = User::factory()->create();
    $chat = Chat::create(['type' => 'direct']);
    $chat->users()->attach([$user->id, $colleague->id]);

    $this->actingAs($user);

    Livewire::test('pages::chat')
        ->call('selectColleague', $colleague->id)
        ->call('createChat')
        ->assertHasNoErrors()
        ->assertSet('activeChatId', $chat->id);

    expect(Chat::query()->count())->toBe(1);
});

test('selecting a colleague creates a new direct chat', function () {
    $user = User::factory()->create();
    $colleague = User::factory()->create();

    $this->actingAs($user);

    $component = Livewire::test('pages::chat')
        ->call('selectColleague', $colleague->id)
        ->call('createChat')
        ->assertHasNoErrors()
        ->assertSet('showChatList', false);

    $chat = Chat::query()->sole();

    expect($chat->type)->toBe('direct');
    expect($component->get('activeChatId'))->toBe($chat->id);
    $this->assertDatabaseHas('chat_users', ['chat_id' => $chat->id, 'user_id' => $user->id]);
    $this->assertDatabaseHas('chat_users', ['chat_id' => $chat->id, 'user_id' => $colleague->id]);
});

test('leaving a group removes only the current member and returns to the chat list', function () {
    $user = User::factory()->create();
    $colleague = User::factory()->create();
    $chat = Chat::create(['type' => 'group', 'name' => 'Команда']);
    $chat->users()->attach([$user->id, $colleague->id]);
    $message = $chat->messages()->create(['user_id' => $colleague->id, 'body' => 'Остаётся в истории']);

    $this->actingAs($user);

    Livewire::test('pages::chat')
        ->call('leaveChat', $chat->id)
        ->assertSet('activeChatId', 0)
        ->assertSet('showChatList', true)
        ->assertDontSee('Команда');

    $this->assertDatabaseMissing('chat_users', ['chat_id' => $chat->id, 'user_id' => $user->id]);
    $this->assertDatabaseHas('chat_users', ['chat_id' => $chat->id, 'user_id' => $colleague->id]);
    $this->assertModelExists($message);
});

test('users cannot leave other peoples groups or direct chats', function () {
    $user = User::factory()->create();
    $outsider = User::factory()->create();
    $group = Chat::create(['type' => 'group', 'name' => 'Закрытая группа']);
    $group->users()->attach($outsider->id);
    $directChat = Chat::create(['type' => 'direct']);
    $directChat->users()->attach([$user->id, $outsider->id]);

    $this->actingAs($user);

    Livewire::test('pages::chat')->call('leaveChat', $group->id)->assertStatus(404);
    Livewire::test('pages::chat')->call('leaveChat', $directChat->id)->assertStatus(404);

    $this->assertDatabaseHas('chat_users', ['chat_id' => $group->id, 'user_id' => $outsider->id]);
    $this->assertDatabaseHas('chat_users', ['chat_id' => $directChat->id, 'user_id' => $user->id]);
});

test('a group member can invite a colleague from the group details', function () {
    $user = User::factory()->create();
    $member = User::factory()->create(['name' => 'Участник']);
    $invitee = User::factory()->create(['name' => 'Новый коллега']);
    $chat = Chat::create(['type' => 'group', 'name' => 'Проект']);
    $chat->users()->attach([$user->id, $member->id]);

    $this->actingAs($user);

    $component = Livewire::test('pages::chat')
        ->call('selectChat', $chat->id)
        ->call('toggleDetails')
        ->call('openInviteModal', $chat->id)
        ->assertSet('showInviteModal', true)
        ->assertSee('data-test="invite-colleague-search"', false);

    expect(array_column($component->instance()->invitableColleagues, 'id'))->toBe([$invitee->id]);

    $component->set('selectedInviteeId', $invitee->id)
        ->call('inviteColleague')
        ->assertHasNoErrors()
        ->assertSet('showInviteModal', false)
        ->assertSee('Новый коллега');

    $this->assertDatabaseHas('chat_users', ['chat_id' => $chat->id, 'user_id' => $invitee->id]);
});

test('an existing participant cannot be invited twice', function () {
    $user = User::factory()->create();
    $member = User::factory()->create();
    $chat = Chat::create(['type' => 'group', 'name' => 'Проект']);
    $chat->users()->attach([$user->id, $member->id]);

    $this->actingAs($user);

    Livewire::test('pages::chat')
        ->call('openInviteModal', $chat->id)
        ->set('selectedInviteeId', $member->id)
        ->call('inviteColleague')
        ->assertHasErrors(['selectedInviteeId'])
        ->assertSet('showInviteModal', true);

    $this->assertDatabaseCount('chat_users', 2);
});

test('the chat list invitation targets the selected group instead of the open chat', function () {
    $user = User::factory()->create();
    $invitee = User::factory()->create();
    $openChat = Chat::create(['type' => 'group', 'name' => 'Открытый чат']);
    $targetChat = Chat::create(['type' => 'group', 'name' => 'Другая группа']);
    $openChat->users()->attach($user->id);
    $targetChat->users()->attach($user->id);

    $this->actingAs($user);

    Livewire::test('pages::chat')
        ->call('selectChat', $openChat->id)
        ->call('openInviteModal', $targetChat->id)
        ->set('selectedInviteeId', $invitee->id)
        ->call('inviteColleague')
        ->assertHasNoErrors()
        ->assertSet('activeChatId', $openChat->id);

    $this->assertDatabaseHas('chat_users', ['chat_id' => $targetChat->id, 'user_id' => $invitee->id]);
    $this->assertDatabaseMissing('chat_users', ['chat_id' => $openChat->id, 'user_id' => $invitee->id]);
});

test('only group members can open an invitation and invite colleagues', function () {
    $user = User::factory()->create();
    $outsider = User::factory()->create();
    $invitee = User::factory()->create();
    $group = Chat::create(['type' => 'group', 'name' => 'Закрытая группа']);
    $group->users()->attach($outsider->id);
    $directChat = Chat::create(['type' => 'direct']);
    $directChat->users()->attach([$user->id, $outsider->id]);
    $formerGroup = Chat::create(['type' => 'group', 'name' => 'Бывшая группа']);
    $formerGroup->users()->attach($user->id);

    $this->actingAs($user);

    Livewire::test('pages::chat')->call('openInviteModal', $group->id)->assertStatus(404);
    Livewire::test('pages::chat')->call('openInviteModal', $directChat->id)->assertStatus(404);

    $component = Livewire::test('pages::chat')->call('openInviteModal', $formerGroup->id);
    $formerGroup->users()->detach($user->id);
    $component->set('selectedInviteeId', $invitee->id)->call('inviteColleague')->assertStatus(404);

    $this->assertDatabaseMissing('chat_users', ['chat_id' => $group->id, 'user_id' => $invitee->id]);
    $this->assertDatabaseMissing('chat_users', ['chat_id' => $formerGroup->id, 'user_id' => $invitee->id]);
});

test('a group owner can remove a member after confirmation', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create(['name' => 'Удаляемый участник']);
    $chat = Chat::create(['type' => 'group', 'name' => 'Команда']);
    $chat->users()->attach($owner->id, ['role' => 'admin']);
    $chat->users()->attach($member->id);
    $message = $chat->messages()->create(['user_id' => $member->id, 'body' => 'История остаётся']);

    $this->actingAs($owner);

    $component = Livewire::test('pages::chat')
        ->call('selectChat', $chat->id)
        ->call('toggleDetails')
        ->assertSee('data-test="remove-participant-button"', false)
        ->call('confirmRemoveParticipant', $member->id)
        ->assertSet('showRemoveParticipantModal', true)
        ->assertSee('Удалить участника?')
        ->call('removeParticipant')
        ->assertSet('showRemoveParticipantModal', false);

    expect(array_column($component->instance()->participants, 'id'))->not->toContain($member->id);

    $this->assertDatabaseMissing('chat_users', ['chat_id' => $chat->id, 'user_id' => $member->id]);
    $this->assertDatabaseHas('chat_users', ['chat_id' => $chat->id, 'user_id' => $owner->id, 'role' => 'admin']);
    $this->assertModelExists($message);

    $this->actingAs($member);

    Livewire::test('pages::chat')->call('selectChat', $chat->id)->assertStatus(403);
});

test('regular group members cannot remove participants', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $chat = Chat::create(['type' => 'group', 'name' => 'Команда']);
    $chat->users()->attach($owner->id, ['role' => 'admin']);
    $chat->users()->attach($member->id);

    $this->actingAs($member);

    Livewire::test('pages::chat')
        ->call('selectChat', $chat->id)
        ->call('toggleDetails')
        ->assertDontSee('data-test="remove-participant-button"', false)
        ->call('confirmRemoveParticipant', $owner->id)
        ->assertStatus(404);

    Livewire::test('pages::chat')->call('removeParticipant')->assertStatus(404);

    $this->assertDatabaseHas('chat_users', ['chat_id' => $chat->id, 'user_id' => $owner->id]);
});

test('the owner cannot remove themselves or another owner', function () {
    $owner = User::factory()->create();
    $otherOwner = User::factory()->create();
    $outsider = User::factory()->create();
    $chat = Chat::create(['type' => 'group', 'name' => 'Команда']);
    $chat->users()->attach($owner->id, ['role' => 'admin']);
    $chat->users()->attach($otherOwner->id, ['role' => 'admin']);

    $this->actingAs($owner);

    Livewire::test('pages::chat')->call('selectChat', $chat->id)->call('confirmRemoveParticipant', $owner->id)->assertStatus(404);
    Livewire::test('pages::chat')->call('selectChat', $chat->id)->call('confirmRemoveParticipant', $otherOwner->id)->assertStatus(404);
    Livewire::test('pages::chat')->call('selectChat', $chat->id)->call('confirmRemoveParticipant', $outsider->id)->assertStatus(404);

    $this->assertDatabaseHas('chat_users', ['chat_id' => $chat->id, 'user_id' => $otherOwner->id, 'role' => 'admin']);
});

test('removal checks owner rights again when the confirmation is submitted', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $chat = Chat::create(['type' => 'group', 'name' => 'Команда']);
    $chat->users()->attach($owner->id, ['role' => 'admin']);
    $chat->users()->attach($member->id);

    $this->actingAs($owner);

    $component = Livewire::test('pages::chat')->call('selectChat', $chat->id)->call('confirmRemoveParticipant', $member->id);
    $chat->users()->updateExistingPivot($owner->id, ['role' => 'member']);

    $component->call('removeParticipant')->assertStatus(404);

    $this->assertDatabaseHas('chat_users', ['chat_id' => $chat->id, 'user_id' => $member->id]);
});
