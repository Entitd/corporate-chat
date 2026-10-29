<?php

use App\Models\User;

test('a shared draft URL is preserved when authentication is required', function () {
    $url = route('chat.index', ['share' => '12345678-1234-1234-1234-123456789abc']);

    $this->get($url)
        ->assertRedirect(route('login'))
        ->assertSessionHas('url.intended', $url);
});

test('shared content opens a chat picker without sending a message', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('chat.index', ['share' => '12345678-1234-1234-1234-123456789abc']))
        ->assertOk()
        ->assertSee('data-test="share-target"', false)
        ->assertSee('Выберите чат в списке ниже.')
        ->assertSee('data-test="attach-shared-files"', false);

    $this->assertDatabaseCount('messages', 0);
    $this->assertDatabaseCount('attachments', 0);
});
