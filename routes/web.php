<?php

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;

Route::get('/', function (): RedirectResponse {
    return to_route(auth()->check() ? 'chat.index' : 'login');
})->name('home');

require __DIR__.'/chat.php';
require __DIR__.'/settings.php';
