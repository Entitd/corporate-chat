<?php

use App\Models\Attachment;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('chat', 'pages::chat')->name('chat.index');
    Route::get('chat/attachments/{attachment}', function (Attachment $attachment) {
        abort_unless(
            $attachment->message()
                ->whereHas('chat.users', fn ($users) => $users->whereKey(auth()->id()))
                ->exists(),
            403,
        );

        abort_unless(Storage::disk('local')->exists($attachment->file_path), 404);

        return Storage::disk('local')->download($attachment->file_path, $attachment->file_name);
    })->name('chat.attachments.download');
});
