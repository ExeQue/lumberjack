<?php

declare(strict_types=1);

use App\Models\User;
use App\Providers\HorizonServiceProvider;

covers(HorizonServiceProvider::class);

describe('the Horizon dashboard', function () {
    it('turns away guests and members, even in local', function () {
        app()->detectEnvironment(fn (): string => 'local');

        expect($this->get('/horizon')->status())->toBeIn([302, 403]);
        $this->actingAs(User::factory()->create())->get('/horizon')->assertForbidden();
        $this->actingAs(User::factory()->create())->getJson('/horizon/api/stats')->assertForbidden();
    });

    it('lets system administrators in', function () {
        $this->actingAs(User::factory()->create(['is_admin' => true]))->get('/horizon')->assertOk();
    });
});
