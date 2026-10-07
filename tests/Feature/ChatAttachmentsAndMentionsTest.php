<?php

use App\Models\Attachment;
use App\Models\Chat;
use App\Models\User;
use App\Notifications\ChatMentioned;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

function chatWithMembers(User ...$users): Chat
{
    $chat = Chat::create(['name' => 'Рабочая группа', 'type' => 'group']);
    $chat->users()->attach(array_map(fn (User $user): int => $user->id, $users));

    return $chat;
}

test('participant can send files without text and download them privately', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    $colleague = User::factory()->create();
    $chat = chatWithMembers($user, $colleague);
    $this->actingAs($user);

    Livewire::test('pages::chat')
        ->call('selectChat', $chat->id)
        ->set('pendingFiles', [
            UploadedFile::fake()->create('report.pdf', 20, 'application/pdf'),
            UploadedFile::fake()->create('notes.txt', 2, 'text/plain'),
        ])
        ->assertSee('report.pdf')
        ->call('sendMessage')
        ->assertSet('pendingFiles', [])
        ->assertSee('report.pdf')
        ->assertSee('notes.txt');

    $message = $chat->messages()->firstOrFail();
    expect($message->body)->toBeNull();
    expect($message->attachments()->count())->toBe(2);

    $attachment = Attachment::where('file_name', 'report.pdf')->firstOrFail();
    Storage::disk('local')->assertExists($attachment->file_path);
    Storage::disk('public')->assertMissing($attachment->file_path);

    $this->get(route('chat.attachments.download', $attachment))->assertOk()->assertDownload('report.pdf');

    $this->actingAs(User::factory()->create());
    $this->get(route('chat.attachments.download', $attachment))->assertForbidden();
});

test('oversized file cannot be sent', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    $chat = chatWithMembers($user);
    $this->actingAs($user);

    Livewire::test('pages::chat')
        ->call('selectChat', $chat->id)
        ->set('pendingFiles', [UploadedFile::fake()->create('large.bin', 2049)])
        ->call('sendMessage')
        ->assertHasErrors(['pendingFiles.0' => 'max'])
        ->assertSee('Файл должен быть не больше 2 МБ.');

    expect($chat->messages()->count())->toBe(0);
});

test('more than three files cannot be sent', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    $chat = chatWithMembers($user);
    $this->actingAs($user);

    Livewire::test('pages::chat')
        ->call('selectChat', $chat->id)
        ->set('pendingFiles', array_map(
            fn (int $number): UploadedFile => UploadedFile::fake()->create("file-{$number}.txt", 1),
            range(1, 4),
        ))
        ->call('sendMessage')
        ->assertHasErrors(['pendingFiles' => 'max'])
        ->assertSee('Можно прикрепить не больше 3 файлов.');

    expect($chat->messages()->count())->toBe(0);
});

test('mentions can be selected only from members of the active chat', function () {
    $user = User::factory()->create(['name' => 'Алиса']);
    $colleague = User::factory()->create(['name' => 'Борис']);
    $outsider = User::factory()->create(['name' => 'Чужой']);
    $chat = chatWithMembers($user, $colleague);
    $this->actingAs($user);

    $component = Livewire::test('pages::chat')
        ->call('selectChat', $chat->id)
        ->set('messageBody', 'Привет, @Бо')
        ->assertSet('showMentionPicker', true);

    expect(array_column($component->instance()->mentionCandidates, 'id'))->toBe([$colleague->id]);

    $component
        ->call('mentionColleague', $colleague->id)
        ->assertSet('messageBody', 'Привет, @Борис ')
        ->call('sendMessage')
        ->assertSee('data-test="message-mention-link"', false)
        ->assertSet('selectedMentionIds', []);

    $message = $chat->messages()->firstOrFail();
    expect($message->body)->toBe('Привет, @Борис');
    expect($message->mentions()->pluck('users.id')->all())->toBe([$colleague->id]);
    $notification = $colleague->notifications()->firstOrFail();
    expect($notification->type)->toBe(ChatMentioned::class)
        ->and($notification->data['message_id'])->toBe($message->id)
        ->and($notification->data['author_name'])->toBe('Алиса');

    $this->get(route('chat.members.show', ['chat' => $chat, 'user' => $colleague]))
        ->assertOk()
        ->assertSee('Борис');

    Livewire::test('pages::chat')
        ->call('selectChat', $chat->id)
        ->call('showMentionProfile', $colleague->id)
        ->assertSet('showMemberModal', true)
        ->assertSee('Открыть профиль');

    Livewire::test('pages::chat')->call('selectChat', $chat->id)->call('mentionColleague', $outsider->id)->assertForbidden();
    Livewire::test('pages::chat')
        ->call('selectChat', $chat->id)
        ->set('messageBody', 'Подделка @Чужой')
        ->set('selectedMentionIds', [$outsider->id])
        ->call('sendMessage')
        ->assertForbidden();

    foreach (range(1, 31) as $number) {
        $chat->messages()->create(['user_id' => $user->id, 'body' => "Позднее сообщение {$number}"]);
    }

    $this->actingAs($colleague);
    Livewire::test('pages::chat')
        ->assertSee('Вас упомянул Алиса')
        ->call('openMentionNotification', $notification->id)
        ->assertSet('activeChatId', $chat->id)
        ->assertSet('visibleMessageCount', 60)
        ->assertSee('data-test="message-mention-link"', false)
        ->assertDispatched('focus-chat-message');

    expect($notification->fresh()->read_at)->not->toBeNull();

    $this->actingAs($outsider);
    $this->get(route('chat.members.show', ['chat' => $chat, 'user' => $colleague]))->assertNotFound();
    Livewire::test('pages::chat')->call('openMentionNotification', $notification->id)->assertNotFound();
});

test('closing the mention picker clears its search and preserves the message draft', function () {
    $user = User::factory()->create();
    chatWithMembers($user);
    $this->actingAs($user);

    Livewire::test('pages::chat')
        ->set('messageBody', 'Привет, @Бор')
        ->assertSet('showMentionPicker', true)
        ->assertSet('mentionSearch', 'Бор')
        ->call('closeMentionPicker')
        ->assertSet('showMentionPicker', false)
        ->assertSet('mentionSearch', '')
        ->assertSet('messageBody', 'Привет, @Бор');
});

test('plain at text does not create a mention notification', function () {
    $user = User::factory()->create();
    $colleague = User::factory()->create(['name' => 'Борис']);
    $chat = chatWithMembers($user, $colleague);
    $this->actingAs($user);

    Livewire::test('pages::chat')
        ->call('selectChat', $chat->id)
        ->call('sendMessage', 'Привет, @Борис')
        ->assertDontSee('data-test="message-mention-link"', false);

    expect($chat->messages()->firstOrFail()->mentions()->count())->toBe(0)
        ->and($colleague->notifications()->count())->toBe(0);
});

test('colleagues with the same name get distinct mention links and notifications', function () {
    $sender = User::factory()->create(['name' => 'Алиса']);
    $first = User::factory()->create(['name' => 'Борис']);
    $second = User::factory()->create(['name' => 'Борис']);
    $chat = chatWithMembers($sender, $first, $second);
    $this->actingAs($sender);

    $component = Livewire::test('pages::chat')
        ->call('selectChat', $chat->id)
        ->call('mentionColleague', $first->id)
        ->call('mentionColleague', $second->id)
        ->call('sendMessage');

    $message = $chat->messages()->firstOrFail();
    $segments = collect($component->instance()->messages['data'][0]['body_segments']);

    expect($message->body)->toBe('@Борис @Борис')
        ->and($segments->pluck('user_id')->filter()->values()->all())->toBe([$first->id, $second->id])
        ->and($first->notifications()->count())->toBe(1)
        ->and($second->notifications()->count())->toBe(1);

    $component
        ->assertSee(route('chat.members.show', ['chat' => $chat, 'user' => $first]), false)
        ->assertSee(route('chat.members.show', ['chat' => $chat, 'user' => $second]), false);
});

test('one mention token cannot notify two colleagues with the same name', function () {
    $sender = User::factory()->create();
    $first = User::factory()->create(['name' => 'Борис']);
    $second = User::factory()->create(['name' => 'Борис']);
    $chat = chatWithMembers($sender, $first, $second);
    $this->actingAs($sender);

    Livewire::test('pages::chat')
        ->call('selectChat', $chat->id)
        ->set('selectedMentionIds', [$first->id, $second->id])
        ->call('sendMessage', 'Привет, @Борис');

    expect($chat->messages()->firstOrFail()->mentions()->pluck('users.id')->all())->toBe([$first->id])
        ->and($first->notifications()->count())->toBe(1)
        ->and($second->notifications()->count())->toBe(0);
});

test('files cannot be sent to another user chat by changing the public id', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    $outsider = User::factory()->create();
    chatWithMembers($user);
    $privateChat = chatWithMembers($outsider);
    $this->actingAs($user);

    Livewire::test('pages::chat')
        ->set('pendingFiles', [UploadedFile::fake()->create('secret.txt', 1)])
        ->set('activeChatId', $privateChat->id)
        ->assertForbidden();

    expect($privateChat->messages()->count())->toBe(0);
});
