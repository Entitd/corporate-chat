<?php

use App\Events\MessageCreated;
use App\Models\Chat;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('a reply saves its parent and displays escaped quoted text', function () {
    $user = User::factory()->create();
    $author = User::factory()->create(['name' => 'Автор цитаты']);
    $chat = Chat::create(['type' => 'direct']);
    $chat->users()->attach([$user->id, $author->id]);
    $source = $chat->messages()->create(['user_id' => $author->id, 'body' => '<script>цитата</script>']);
    $this->actingAs($user);
    Event::fake([MessageCreated::class]);

    Livewire::test('pages::chat')->call('selectChat', $chat->id)
        ->call('replyToMessage', $source->id)->assertSet('replyToMessageId', $source->id)
        ->assertSee('reply-preview')->assertSee('Автор цитаты')
        ->assertSee('<script>цитата</script>')->assertDontSee('<script>цитата</script>', false)
        ->assertDispatched('message-reply-selected')
        ->call('sendMessage', 'Ответ на цитату')->assertSet('replyToMessageId', null)
        ->assertDontSee('reply-preview')->assertSee('Ответ на цитату')->assertSee('Автор цитаты');

    $this->assertDatabaseHas('messages', ['chat_id' => $chat->id, 'user_id' => $user->id, 'parent_id' => $source->id, 'body' => 'Ответ на цитату']);
    Event::assertDispatched(MessageCreated::class, fn ($event): bool => $event->chatId === $chat->id);
});

test('cancelling a reply and changing chats clear the quote', function () {
    $user = User::factory()->create();
    $chat = Chat::create(['type' => 'group', 'name' => 'Первый']);
    $other = Chat::create(['type' => 'group', 'name' => 'Второй']);
    $chat->users()->attach($user);
    $other->users()->attach($user);
    $source = $chat->messages()->create(['user_id' => $user->id, 'body' => 'Цитата']);
    $this->actingAs($user);

    Livewire::test('pages::chat')->call('selectChat', $chat->id)
        ->set('messageBody', 'Черновик')->call('replyToMessage', $source->id)
        ->call('cancelReply')->assertSet('replyToMessageId', null)->assertSet('messageBody', 'Черновик')
        ->call('replyToMessage', $source->id)->call('selectChat', $other->id)
        ->assertSet('replyToMessageId', null)->call('sendMessage', 'Без цитаты');

    $this->assertDatabaseHas('messages', ['chat_id' => $other->id, 'body' => 'Без цитаты', 'parent_id' => null]);
});

test('forwarding copies text and downloadable attachments and broadcasts to the target', function () {
    $user = User::factory()->create();
    $author = User::factory()->create(['name' => 'Автор оригинала']);
    $recipient = User::factory()->create();
    $sourceChat = Chat::create(['type' => 'group', 'name' => 'Источник']);
    $target = Chat::create(['type' => 'group', 'name' => 'Получатели']);
    $sourceChat->users()->attach([$user->id, $author->id]);
    $target->users()->attach([$user->id, $recipient->id]);
    $source = $sourceChat->messages()->create(['user_id' => $author->id, 'body' => 'Пересылаемый текст']);
    $original = $source->attachments()->create(['file_path' => 'chat-attachments/original.txt', 'file_name' => 'original.txt', 'file_type' => 'text/plain', 'file_size' => 10]);
    $source->mentions()->attach($user);
    Storage::fake('local');
    Storage::disk('local')->put($original->file_path, 'Содержимое');
    $this->actingAs($user);
    Event::fake([MessageCreated::class]);

    Livewire::test('pages::chat')->call('selectChat', $sourceChat->id)
        ->set('messageBody', 'Мой черновик')
        ->call('openForwardMessage', $source->id)->assertSet('showForwardModal', true)
        ->set('forwardSearch', 'Получатели')->assertSee('Получатели')
        ->call('forwardMessage', $target->id)->assertSet('showForwardModal', false)
        ->assertSet('forwardMessageId', null)->assertSet('messageBody', 'Мой черновик')
        ->assertDispatched('message-forwarded')
        ->call('selectChat', $target->id)->assertSee('Переслано от')->assertSee('Автор оригинала')
        ->assertSee('Пересылаемый текст')->assertSee('original.txt');

    $forward = $target->messages()->sole();
    expect($forward->user_id)->toBe($user->id)
        ->and($forward->forwarded_message_id)->toBe($source->id)
        ->and($forward->parent_id)->toBeNull()
        ->and($forward->body)->toBe($source->body)
        ->and($forward->mentions)->toHaveCount(0);
    $this->assertDatabaseHas('attachments', ['message_id' => $forward->id, 'file_path' => $original->file_path]);
    Event::assertDispatched(MessageCreated::class, fn ($event): bool => $event->chatId === $target->id && $event->messageId === $forward->id);
    $this->actingAs($recipient)->get(route('chat.attachments.download', $forward->attachments->sole()))->assertDownload('original.txt');
    $this->get(route('chat.attachments.download', $original))->assertForbidden();
});

test('reply and forward selection reject messages from another chat', function (string $action) {
    $user = User::factory()->create();
    $own = Chat::create(['type' => 'group']);
    $private = Chat::create(['type' => 'group']);
    $own->users()->attach($user);
    $source = $private->messages()->create(['user_id' => $user->id, 'body' => 'Скрыто']);
    $this->actingAs($user);

    Livewire::test('pages::chat')->call('selectChat', $own->id)->call($action, $source->id)->assertStatus(404);

    expect(Message::count())->toBe(1);
})->with(['replyToMessage', 'openForwardMessage']);

test('forwarding rejects an inaccessible target without broadcasting', function () {
    $user = User::factory()->create();
    $sourceChat = Chat::create(['type' => 'group']);
    $target = Chat::create(['type' => 'group']);
    $sourceChat->users()->attach($user);
    $source = $sourceChat->messages()->create(['user_id' => $user->id, 'body' => 'Текст']);
    $this->actingAs($user);
    Event::fake([MessageCreated::class]);

    Livewire::test('pages::chat')->call('selectChat', $sourceChat->id)
        ->call('openForwardMessage', $source->id)->call('forwardMessage', $target->id)->assertStatus(403);

    expect($target->messages()->count())->toBe(0);
    Event::assertNotDispatched(MessageCreated::class);
});

test('forwarding rechecks source membership after opening the picker', function () {
    $user = User::factory()->create();
    $sourceChat = Chat::create(['type' => 'group']);
    $target = Chat::create(['type' => 'group']);
    $sourceChat->users()->attach($user);
    $target->users()->attach($user);
    $source = $sourceChat->messages()->create(['user_id' => $user->id, 'body' => 'Текст']);
    $this->actingAs($user);
    $component = Livewire::test('pages::chat')->call('selectChat', $sourceChat->id)->call('openForwardMessage', $source->id);
    $sourceChat->users()->detach($user);
    Event::fake([MessageCreated::class]);

    $component->call('forwardMessage', $target->id)->assertStatus(403);

    expect($target->messages()->count())->toBe(0);
    Event::assertNotDispatched(MessageCreated::class);
});

test('a file-only message can be forwarded to the current chat only once per selection', function () {
    $user = User::factory()->create();
    $chat = Chat::create(['type' => 'group']);
    $chat->users()->attach($user);
    $source = $chat->messages()->create(['user_id' => $user->id]);
    $source->attachments()->create(['file_path' => 'file.txt', 'file_name' => 'file.txt', 'file_type' => 'text/plain', 'file_size' => 1]);
    $this->actingAs($user);

    Livewire::test('pages::chat')->call('selectChat', $chat->id)
        ->call('replyToMessage', $source->id)->assertSee('Вложение')->call('cancelReply')
        ->call('openForwardMessage', $source->id)->call('forwardMessage', $chat->id)
        ->assertDispatched('message-sent')->assertSee('Переслано от')
        ->call('forwardMessage', $chat->id)->assertStatus(404);

    expect($chat->messages()->count())->toBe(2);
    $this->assertDatabaseHas('messages', ['chat_id' => $chat->id, 'body' => null, 'forwarded_message_id' => $source->id]);
});

test('forwarding again preserves the original author', function () {
    $user = User::factory()->create();
    $author = User::factory()->create(['name' => 'Первоначальный автор']);
    $chat = Chat::create(['type' => 'group']);
    $chat->users()->attach([$user->id, $author->id]);
    $source = $chat->messages()->create(['user_id' => $author->id, 'body' => 'Оригинал']);
    $first = $chat->messages()->create(['user_id' => $user->id, 'body' => $source->body, 'forwarded_message_id' => $source->id]);
    $this->actingAs($user);

    Livewire::test('pages::chat')->call('selectChat', $chat->id)
        ->call('openForwardMessage', $first->id)->call('forwardMessage', $chat->id)
        ->assertSee('Первоначальный автор');

    expect($chat->messages()->latest('id')->first()->forwarded_message_id)->toBe($source->id);
});

test('an invalid reply retains the quote and does not broadcast', function () {
    $user = User::factory()->create();
    $chat = Chat::create(['type' => 'group']);
    $chat->users()->attach($user);
    $source = $chat->messages()->create(['user_id' => $user->id, 'body' => 'Цитата']);
    $this->actingAs($user);
    Event::fake([MessageCreated::class]);

    Livewire::test('pages::chat')->call('selectChat', $chat->id)
        ->call('replyToMessage', $source->id)->call('sendMessage', str_repeat('а', 5001))
        ->assertHasErrors(['messageBody' => 'max'])->assertSet('replyToMessageId', $source->id)
        ->assertSee('reply-preview');

    expect($chat->messages()->count())->toBe(1);
    Event::assertNotDispatched(MessageCreated::class);
});

test('a deleted source cannot be replied to or forwarded', function (string $selection, string $action) {
    $user = User::factory()->create();
    $chat = Chat::create(['type' => 'group']);
    $chat->users()->attach($user);
    $source = $chat->messages()->create(['user_id' => $user->id, 'body' => 'Удаляемое сообщение']);
    $this->actingAs($user);
    $component = Livewire::test('pages::chat')->call('selectChat', $chat->id)->call($selection, $source->id);
    $source->delete();
    Event::fake([MessageCreated::class]);

    $component->call($action, $action === 'sendMessage' ? 'Ответ' : $chat->id)->assertStatus(404);

    expect($chat->messages()->count())->toBe(0);
    Event::assertNotDispatched(MessageCreated::class);
})->with([
    'reply' => ['replyToMessage', 'sendMessage'],
    'forward' => ['openForwardMessage', 'forwardMessage'],
]);
