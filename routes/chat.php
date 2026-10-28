<?php

use App\Http\Controllers\ChatAttachmentController;
use App\Http\Controllers\PushSubscriptionController;
use App\Models\Chat;
use App\Models\User;
use Illuminate\Support\Facades\Route;

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
    Route::get('chat/attachments/{attachment}', [ChatAttachmentController::class, 'download'])->name('chat.attachments.download');
    Route::get('chat/attachments/{attachment}/preview', [ChatAttachmentController::class, 'preview'])->name('chat.attachments.preview');
});
