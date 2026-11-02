<?php

declare(strict_types=1);

use App\Models\User;

describe('runtime configuration', function () {
    test('pages tell the browser where Reverb is, from the server and not the build', function () {
        User::factory()->create();
        config(['reverb.client' => ['key' => 'k1', 'host' => 'logs.example.com', 'port' => 443, 'scheme' => 'https']]);

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('<meta name="lumberjack-reverb" content="'.e(json_encode(['key' => 'k1', 'host' => 'logs.example.com', 'port' => 443, 'scheme' => 'https'])).'">', false);
    });
});
