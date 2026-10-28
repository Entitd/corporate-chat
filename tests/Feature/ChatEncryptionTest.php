<?php

use App\Models\Chat;
use App\Models\Message;
use App\Models\User;
use App\Notifications\ChatMentioned;
use App\Services\EncryptedAttachments;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

test('messages and notification excerpts are encrypted in the database', function () {
    $user = User::factory()->create();
    $chat = Chat::create(['type' => 'group']);
    $message = $chat->messages()->create(['user_id' => $user->id, 'body' => 'Секретный текст']);
    $user->notify(new ChatMentioned($chat->id, $message->id, 'Чат', 'Автор', 'Секретный текст'));

    $rawBody = DB::table('messages')->where('id', $message->id)->value('body');
    $rawData = DB::table('notifications')->sole()->data;

    expect($rawBody)->not->toContain('Секретный текст')
        ->and(Crypt::decryptString($rawBody))->toBe('Секретный текст')
        ->and($message->fresh()->body)->toBe('Секретный текст')
        ->and($rawData)->not->toContain('Секретный текст')
        ->and($user->notifications()->sole()->data['excerpt'])->toBe('Секретный текст');

    DB::table('messages')->where('id', $message->id)->update(['body' => 'damaged ciphertext']);
    expect(fn () => $message->fresh()->body)->toThrow(DecryptException::class);
});

test('binary uploads are encrypted and forwarded downloads return original bytes', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    $recipient = User::factory()->create();
    $source = Chat::create(['type' => 'group']);
    $target = Chat::create(['type' => 'group']);
    $source->users()->attach($user);
    $target->users()->attach([$user->id, $recipient->id]);
    $bytes = "\x00\xff\x01Private binary file\x00";
    $this->actingAs($user);

    $component = Livewire::test('pages::chat')->call('selectChat', $source->id)
        ->set('pendingFiles', [UploadedFile::fake()->createWithContent('secret.bin', $bytes)])
        ->call('sendMessage');
    $message = $source->messages()->sole();
    $attachment = $message->attachments()->sole();

    expect($attachment->is_encrypted)->toBeTrue()
        ->and(Storage::disk('local')->get($attachment->file_path))->not->toContain('Private binary file')
        ->and(app(EncryptedAttachments::class)->contents($attachment))->toBe($bytes);

    $this->get(route('chat.attachments.download', $attachment))->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertStreamedContent($bytes);

    $component->call('openForwardMessage', $message->id)->call('forwardMessage', $target->id);
    $forward = $target->messages()->sole()->attachments()->sole();
    expect($forward->is_encrypted)->toBeTrue();
    $this->actingAs($recipient)->get(route('chat.attachments.download', $forward))->assertStreamedContent($bytes);
    $this->get(route('chat.attachments.download', $attachment))->assertForbidden();

    $payload = json_decode(base64_decode(Storage::disk('local')->get($forward->file_path)), true);
    $payload['mac'] = str_repeat('0', 64);
    Storage::disk('local')->put($forward->file_path, base64_encode(json_encode($payload)));
    $this->withoutExceptionHandling();
    expect(fn () => $this->get(route('chat.attachments.download', $forward)))->toThrow(DecryptException::class);
});

test('legacy text migration preserves deleted messages nulls and existing notifications', function () {
    $user = User::factory()->create();
    $chat = Chat::create(['type' => 'group']);
    $id = DB::table('messages')->insertGetId([
        'chat_id' => $chat->id, 'user_id' => $user->id, 'body' => 'Старый секрет', 'deleted_at' => now(),
    ]);
    $empty = DB::table('messages')->insertGetId(['chat_id' => $chat->id, 'user_id' => $user->id, 'body' => null]);
    DB::table('notifications')->insert([
        'id' => (string) Str::uuid(), 'type' => ChatMentioned::class,
        'notifiable_type' => $user->getMorphClass(), 'notifiable_id' => $user->id,
        'data' => json_encode(['excerpt' => 'Старый секрет']),
    ]);

    $migration = require database_path('migrations/2026_10_27_000002_encrypt_chat_text.php');
    $migration->up();

    expect(Message::withTrashed()->findOrFail($id)->body)->toBe('Старый секрет')
        ->and(Message::findOrFail($empty)->body)->toBeNull()
        ->and($user->notifications()->sole()->data['excerpt'])->toBe('Старый секрет')
        ->and(DB::table('messages')->where('id', $id)->value('body'))->not->toBe('Старый секрет');
});

test('legacy file conversion handles shared paths and is safe to rerun', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    $chat = Chat::create(['type' => 'group']);
    $chat->users()->attach($user);
    $message = $chat->messages()->create(['user_id' => $user->id]);
    $attributes = ['file_path' => 'chat-attachments/old.txt', 'file_name' => 'old.txt', 'file_type' => 'text/plain'];
    $original = $message->attachments()->create($attributes);
    $forward = $message->attachments()->create($attributes);
    Storage::disk('local')->put($original->file_path, 'Старый файл');

    $this->artisan('chat:encrypt-attachments')->assertSuccessful();
    $original->refresh();
    $forward->refresh();
    $ciphertext = Storage::disk('local')->get($original->file_path);

    expect($original->is_encrypted)->toBeTrue()
        ->and($forward->file_path)->toBe($original->file_path)
        ->and($forward->is_encrypted)->toBeTrue()
        ->and(Crypt::decryptString($ciphertext))->toBe('Старый файл');
    Storage::disk('local')->assertMissing('chat-attachments/old.txt');
    $this->artisan('chat:encrypt-attachments')->assertSuccessful();
    expect(Storage::disk('local')->get($original->file_path))->toBe($ciphertext);
    $this->actingAs($user)->get(route('chat.attachments.download', $forward))->assertStreamedContent('Старый файл');
});

test('missing legacy files are reported without changing their database references', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    $chat = Chat::create(['type' => 'group']);
    $message = $chat->messages()->create(['user_id' => $user->id]);
    $attachment = $message->attachments()->create([
        'file_path' => 'missing.txt', 'file_name' => 'missing.txt', 'file_type' => 'text/plain',
    ]);

    $this->artisan('chat:encrypt-attachments')->assertFailed();
    expect($attachment->fresh()->is_encrypted)->toBeFalse()
        ->and($attachment->fresh()->file_path)->toBe('missing.txt');
    Storage::disk('local')->put('missing.txt', 'Recovered');
    $this->artisan('chat:encrypt-attachments')->assertSuccessful();
    expect(app(EncryptedAttachments::class)->contents($attachment->fresh()))->toBe('Recovered');
});

test('plaintext cleanup resumes after an interrupted conversion', function () {
    Storage::fake('local');
    Storage::disk('local')->put('chat-attachments/old.txt', 'plaintext');
    DB::table('chat_encryption_cleanup')->insert(['file_path' => 'chat-attachments/old.txt']);

    $this->artisan('chat:encrypt-attachments')->assertSuccessful();

    Storage::disk('local')->assertMissing('chat-attachments/old.txt');
    expect(DB::table('chat_encryption_cleanup')->count())->toBe(0);
});

test('previous encryption keys keep messages notifications and files readable during rotation', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    $chat = Chat::create(['type' => 'group']);
    $message = $chat->messages()->create(['user_id' => $user->id, 'body' => 'Before rotation']);
    $user->notify(new ChatMentioned($chat->id, $message->id, 'Chat', 'Author', 'Before rotation'));
    $files = app(EncryptedAttachments::class);
    $attachment = $message->attachments()->create([
        'file_path' => $files->storeContents('Original bytes', 'chat-attachments'),
        'file_name' => 'secret.txt', 'file_type' => 'text/plain', 'is_encrypted' => true,
    ]);
    $previousKey = Crypt::getKey();
    Crypt::swap((new Encrypter(random_bytes(32), 'AES-256-CBC'))->previousKeys([$previousKey]));

    expect($message->fresh()->body)->toBe('Before rotation')
        ->and($user->notifications()->sole()->data['excerpt'])->toBe('Before rotation')
        ->and($files->contents($attachment))->toBe('Original bytes');
});
