<?php

namespace Database\Factories;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PushSubscription> */
class PushSubscriptionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $endpoint = 'https://web.push.apple.com/'.fake()->uuid();

        return [
            'user_id' => User::factory(),
            'endpoint' => $endpoint,
            'endpoint_hash' => hash('sha256', $endpoint),
            'public_key' => 'B'.str_repeat('a', 86),
            'auth_token' => str_repeat('b', 22),
            'session_hash' => hash('sha256', fake()->uuid()),
        ];
    }
}
