<?php

declare(strict_types=1);

use App\Domains\Administration\Actions\CheckForUpdateAction;
use App\Domains\Administration\Http\Controllers\AdminUpdateCheckController;
use App\Domains\Shared\Support\AppVersion;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

covers(CheckForUpdateAction::class, AdminUpdateCheckController::class, AppVersion::class);

describe('checking for a newer release', function () {
    beforeEach(function () {
        config(['lumberjack.version' => '0.1.8']);
    });

    it('tells a system administrator about a newer release on every page', function () {
        Http::fake(['api.github.com/*' => Http::response(['tag_name' => 'v0.1.9'])]);

        expect(app(CheckForUpdateAction::class)->execute())->toBeTrue();

        $this->actingAs(User::factory()->systemAdmin()->create())->get(route('about'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('update_available', '0.1.9')
                ->where('update.available', true)
                ->where('update.url', 'https://github.com/ExeQue/lumberjack/releases/tag/v0.1.9'));
    });

    it('tells nobody else', function () {
        Http::fake(['api.github.com/*' => Http::response(['tag_name' => 'v0.1.9'])]);
        app(CheckForUpdateAction::class)->execute();

        $this->actingAs(User::factory()->inTeam()->create())->get(route('about'))
            ->assertInertia(fn (Assert $page) => $page->where('update_available', null)->where('update', null));
    });

    it('offers nothing when the latest release is not newer', function (string $latest) {
        Http::fake(['api.github.com/*' => Http::response(['tag_name' => $latest])]);
        app(CheckForUpdateAction::class)->execute();

        expect(AppVersion::updateAvailable())->toBeNull();
    })->with(['the same release' => 'v0.1.8', 'an older release' => 'v0.1.7']);

    it('offers nothing on a build between releases', function () {
        config(['lumberjack.version' => 'v0.1.4-298-g9885421']);
        Http::fake(['api.github.com/*' => Http::response(['tag_name' => 'v0.1.9'])]);
        app(CheckForUpdateAction::class)->execute();

        expect(AppVersion::updateAvailable())->toBeNull();
    });

    it('makes no request with the check turned off', function () {
        config(['lumberjack.update_check' => false]);
        Http::fake();

        expect(app(CheckForUpdateAction::class)->execute())->toBeFalse();
        Http::assertNothingSent();
    });

    it('keeps the last answer when GitHub cannot be reached', function () {
        Http::fakeSequence('api.github.com/*')
            ->push(['tag_name' => 'v0.1.9'])
            ->pushResponse(fn () => throw new ConnectionException('down'));
        app(CheckForUpdateAction::class)->execute();

        expect(app(CheckForUpdateAction::class)->execute())->toBeFalse()
            ->and(AppVersion::updateAvailable())->toBe('0.1.9');
    });

    it('ignores a response without a release tag', function () {
        Http::fake(['api.github.com/*' => Http::response(['message' => 'rate limited'], 403)]);

        expect(app(CheckForUpdateAction::class)->execute())->toBeFalse()
            ->and(AppVersion::latestRelease())->toBeNull();
    });
});

describe('checking by hand', function () {
    it('lets a system administrator check right away', function () {
        Http::fake(['api.github.com/*' => Http::response(['tag_name' => 'v0.1.9'])]);

        $this->actingAs(User::factory()->systemAdmin()->create())->post(route('admin.update-check'))
            ->assertRedirect()->assertSessionHas('status');

        Http::assertSentCount(1);
    });

    it('says so when the check fails', function () {
        Http::fake(['api.github.com/*' => Http::response([], 500)]);

        $this->actingAs(User::factory()->systemAdmin()->create())->post(route('admin.update-check'))
            ->assertSessionHasErrors('update_check');
    });

    it('refuses anyone but a system administrator', function () {
        Http::fake();

        $this->actingAs(User::factory()->inTeam()->create())->post(route('admin.update-check'))
            ->assertForbidden();

        Http::assertNothingSent();
    });
});
