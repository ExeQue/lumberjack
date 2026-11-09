<?php

declare(strict_types=1);

use App\Domains\Shared\Http\Controllers\AboutController;
use App\Domains\Shared\Support\AppVersion;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

covers(AboutController::class, AppVersion::class);

describe('the about page', function () {
    it('names the release the image was built from, with its notes', function () {
        config(['lumberjack.version' => '0.2.0', 'lumberjack.revision' => '9885421c0ffee1234567']);

        $this->actingAs(User::factory()->inTeam()->create())->get(route('about'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('about')
                ->where('version', '0.2.0')
                ->where('revision', '9885421c0ffe')
                ->where('release_url', 'https://github.com/ExeQue/lumberjack/releases/tag/v0.2.0')
                ->where('changelog_url', 'https://github.com/ExeQue/lumberjack/blob/main/CHANGELOG.md')
                ->where('system', null));
    });

    it('links no release notes for a build between releases', function () {
        config(['lumberjack.version' => 'v0.1.4-298-g9885421']);

        $this->actingAs(User::factory()->inTeam()->create())->get(route('about'))
            ->assertInertia(fn (Assert $page) => $page->where('release_url', null));
    });

    it('shows system administrators what it runs on', function () {
        $this->actingAs(User::factory()->systemAdmin()->create())->get(route('about'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('system.php', PHP_VERSION)
                ->has('system.database'));
    });

    it('gives every page the version for the user menu', function () {
        config(['lumberjack.version' => '0.2.0']);

        $this->actingAs(User::factory()->inTeam()->create())->get(route('projects.index'))
            ->assertInertia(fn (Assert $page) => $page->where('app_version', '0.2.0'));
    });
});
