<?php

use App\Models\User;

test('device notification controls live inside the user menus beside logout', function () {
    $this->actingAs(User::factory()->create());
    $response = $this->get(route('chat.index'))->assertOk();
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
    $xpath = new DOMXPath($document);
    $panels = $xpath->query('//*[@data-test="push-notifications"][@data-location="menu"]');
    expect($panels->length)->toBe(2);
    $banner = $xpath->query('//*[@data-location="banner"]');
    expect($banner->length)->toBe(1);
    expect($banner->item(0)->getAttribute('x-show'))->toBe('checked && !enabled && !busy');

    foreach ($panels as $panel) {
        expect($panel->getAttribute('x-show'))->toBe('true');
        $menu = $xpath->query('ancestor::*[@data-test="chat-user-menu"]', $panel);
        expect($menu->length)->toBe(1);
        expect($xpath->query('.//*[@data-test="logout-button"]', $menu->item(0))->length)->toBe(1);
    }
});
