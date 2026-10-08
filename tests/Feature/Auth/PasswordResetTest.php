<?php

use App\Models\User;
use Illuminate\Support\Facades\Notification;

test('reset password link screen is unavailable', function () {
    $response = $this->get('/forgot-password');

    $response->assertNotFound();
});

test('reset password link cannot be requested', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email])->assertNotFound();

    Notification::assertNothingSent();
});

test('reset password screen is unavailable', function () {
    $this->get('/reset-password/token')->assertNotFound();
});

test('password cannot be reset through the disabled endpoint', function () {
    $user = User::factory()->create();
    $originalPassword = $user->password;

    $this->post('/reset-password', [
        'token' => 'token',
        'email' => $user->email,
        'password' => 'replacement-password',
        'password_confirmation' => 'replacement-password',
    ])->assertNotFound();

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'password' => $originalPassword,
    ]);
});
