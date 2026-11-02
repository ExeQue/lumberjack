<?php

declare(strict_types=1);

use App\Models\User;

describe('the dashboard', function () {
    test('guests are redirected to the login page', function () {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    });

    test('authenticated users are sent on to their projects', function () {
        $this->actingAs(User::factory()->inTeam()->create())
            ->get(route('dashboard'))
            ->assertRedirect(route('projects.index'));
    });
});
