<?php

use App\Models\Chat;
use App\Models\User;
use App\Services\EncryptedAttachments;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
});

function previewAttachment(string $contents, string $mime = 'application/pdf', bool $encrypted = true): array
{
    $user = User::factory()->create();
    $chat = Chat::create(['type' => 'group', 'name' => 'Просмотр файлов']);
    $chat->users()->attach($user);
    $message = $chat->messages()->create(['user_id' => $user->id]);
    $path = $encrypted ? app(EncryptedAttachments::class)->storeContents($contents, 'chat-attachments') : 'chat-attachments/legacy.pdf';
    if (! $encrypted) {
        Storage::disk('local')->put($path, $contents);
    }
    $attachment = $message->attachments()->create([
        'file_path' => $path, 'file_name' => 'Документ.pdf', 'file_type' => $mime,
        'file_size' => strlen($contents), 'is_encrypted' => $encrypted,
    ]);

    return [$user, $chat, $attachment];
}

test('participants can preview encrypted PDFs inline and still download them', function (bool $encrypted) {
    $bytes = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF";
    [$user, $chat, $attachment] = previewAttachment($bytes, encrypted: $encrypted);

    $response = $this->actingAs($user)->get(route('chat.attachments.preview', $attachment));
    $response->assertOk()->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('X-Content-Type-Options', 'nosniff')->assertContent($bytes);
    expect($response->headers->get('Content-Disposition'))->toStartWith('inline;')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
    $download = $this->get(route('chat.attachments.download', $attachment))->assertDownload()->assertStreamedContent($bytes);
    expect($download->headers->get('Content-Disposition'))->toContain("filename*=utf-8''".rawurlencode('Документ.pdf'));

    Livewire::test('pages::chat')->call('selectChat', $chat->id)
        ->assertSee('attachment-preview-link')->assertSee('attachment-download-link')
        ->call('toggleDetails')->assertSee('Общие файлы');
})->with([true, false]);

test('image preview identifies the real bytes instead of trusting the filename or metadata', function () {
    $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Zl1sAAAAASUVORK5CYII=');
    [$user, , $attachment] = previewAttachment($bytes, 'image/png');
    $this->actingAs($user)->get(route('chat.attachments.preview', $attachment))
        ->assertOk()->assertHeader('Content-Type', 'image/png')->assertContent($bytes);
});

test('guests outsiders and former members cannot preview chat files', function () {
    [$user, $chat, $attachment] = previewAttachment('%PDF-1.4');
    $url = route('chat.attachments.preview', $attachment);
    $this->getJson($url)->assertUnauthorized();
    $this->actingAs(User::factory()->create())->get($url)->assertForbidden();
    $chat->users()->detach($user);
    $this->actingAs($user)->get($url)->assertForbidden();
});

test('active document formats disguised as images cannot be previewed', function (string $contents) {
    [$user, , $attachment] = previewAttachment($contents, 'image/png');
    $this->actingAs($user)->get(route('chat.attachments.preview', $attachment))->assertStatus(415);
    $this->get(route('chat.attachments.download', $attachment))->assertDownload();
})->with(['<html><script>alert(1)</script></html>', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>']);

test('missing files and files belonging to deleted messages cannot be previewed', function () {
    [$user, , $attachment] = previewAttachment('%PDF-1.4');
    $url = route('chat.attachments.preview', $attachment);
    Storage::disk('local')->delete($attachment->file_path);
    $this->actingAs($user)->get($url)->assertNotFound();
    $attachment->message->delete();
    $this->get($url)->assertForbidden();
});
