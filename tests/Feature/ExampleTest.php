<?php

use App\Models\User;

test('home redirects guests to login', function () {
    $response = $this->get(route('home'));

    $response->assertRedirect(route('login'));
});

test('home redirects authenticated users to chat', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('home'))->assertRedirect(route('chat.index'));
});
