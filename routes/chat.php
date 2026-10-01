<?php

use Illuminate\Support\Facades\Route;

// Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('chat', 'pages::chat')->name('chat.index');
// });
