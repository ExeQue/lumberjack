<?php

declare(strict_types=1);

use App\Models\User;
use App\Providers\HorizonServiceProvider;

covers(HorizonServiceProvider::class);

describe('the Horizon dashboard', function () {
    it('answers guests and members with a 404, even in local', function () {
        app()->detectEnvironment(fn (): string => 'local');

        // With a user in place, so first-run setup does not redirect first.
        User::factory()->create(['is_admin' => true]);
        $this->get('/horizon')->assertNotFound();
        $this->actingAs(User::factory()->create())->get('/horizon')->assertNotFound();
        $this->actingAs(User::factory()->create())->getJson('/horizon/api/stats')->assertNotFound();
    });

    it('lets system administrators in', function () {
        $this->actingAs(User::factory()->create(['is_admin' => true]))->get('/horizon')->assertOk();
    });
});
