<?php

use App\Http\Controllers\PushSubscriptionController;
use App\Models\Attachment;
use App\Models\Chat;
use App\Models\User;
use App\Services\EncryptedAttachments;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::post('chat/push-subscriptions', [PushSubscriptionController::class, 'store'])->middleware('throttle:30,1')->name('push-subscriptions.store');
    Route::delete('chat/push-subscriptions', [PushSubscriptionController::class, 'destroy'])->middleware('throttle:30,1')->name('push-subscriptions.destroy');
    Route::livewire('chat', 'pages::chat')->name('chat.index');
    Route::get('chat/{chat}/members/{user}', function (Chat $chat, User $user) {
        abort_unless(
            $chat->users()->whereKey(auth()->id())->exists()
                && $chat->users()->whereKey($user->id)->exists(),
            404,
        );

        return view('chat.member', ['chat' => $chat, 'member' => $user]);
    })->name('chat.members.show');
    Route::get('chat/attachments/{attachment}', function (Attachment $attachment) {
        abort_unless(
            $attachment->message()
                ->whereHas('chat.users', fn ($users) => $users->whereKey(auth()->id()))
                ->exists(),
            403,
        );

        abort_unless(Storage::disk('local')->exists($attachment->file_path), 404);

        // Verify the ciphertext before sending any bytes to the client.
        $contents = app(EncryptedAttachments::class)->contents($attachment);

        return response()->streamDownload(function () use ($contents): void {
            echo $contents;
        }, $attachment->file_name, [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    })->name('chat.attachments.download');
});
