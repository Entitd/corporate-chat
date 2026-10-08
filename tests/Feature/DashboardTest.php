<?php

use App\Models\User;

test('dashboard is unavailable to guests', function () {
    $response = $this->get('/dashboard');
    $response->assertNotFound();
});

test('dashboard is unavailable to authenticated users', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get('/dashboard');
    $response->assertNotFound();
});
