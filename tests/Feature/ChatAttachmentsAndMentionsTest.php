<?php

use App\Models\Attachment;
use App\Models\Chat;
use App\Models\User;
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
        ->set('messageBody', 'Привет, @Бо')
        ->assertSet('showMentionPicker', true);

    expect(array_column($component->instance()->mentionCandidates, 'id'))->toBe([$colleague->id]);

    $component
        ->call('mentionColleague', $colleague->id)
        ->assertSet('messageBody', 'Привет, @Борис ')
        ->call('sendMessage')
        ->assertSee('data-test="message-mentions"', false)
        ->assertSet('selectedMentionIds', []);

    $message = $chat->messages()->firstOrFail();
    expect($message->body)->toBe('Привет, @Борис');
    expect($message->mentions()->pluck('users.id')->all())->toBe([$colleague->id]);

    Livewire::test('pages::chat')->call('mentionColleague', $outsider->id)->assertForbidden();
    Livewire::test('pages::chat')
        ->set('messageBody', 'Подделка @Чужой')
        ->set('selectedMentionIds', [$outsider->id])
        ->call('sendMessage')
        ->assertForbidden();
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
