<?php

declare(strict_types=1);

use App\Domains\Administration\Enums\AdminAction;
use App\Domains\Administration\Models\AdminAuditEntry;
use App\Domains\Screens\Actions\ConfirmTvPairingAction;
use App\Domains\Screens\Events\TvPairingConfirmed;
use App\Domains\Screens\Http\Controllers\PairController;
use App\Domains\Screens\Http\Requests\ConfirmTvPairingRequest;
use App\Domains\Screens\Models\Display;
use App\Domains\Screens\Models\Screen;
use App\Domains\Screens\Models\TvPairing;
use App\Domains\Screens\Providers\ScreensServiceProvider;
use App\Domains\Shared\Actions\Concerns\CanHaveReasons;
use App\Domains\Teams\Enums\TeamRole;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

covers(
    PairController::class,
    ConfirmTvPairingRequest::class,
    ConfirmTvPairingAction::class,
    TvPairingConfirmed::class,
    CanHaveReasons::class,
    ScreensServiceProvider::class,
);

beforeEach(function () {
    $this->screen = Screen::factory()->create(['name' => 'Ops']);
    $this->admin = User::factory()->inTeam($this->screen->team, TeamRole::Admin)->create();
    $this->pairing = TvPairing::factory()->create(['code' => 'K7QM2X', 'created_at' => now()->subMinute()]);
});

describe('the confirm page', function () {
    it('asks to sign in first, and comes back after', function () {
        $this->get(route('pair.show', 'K7QM2X'))->assertRedirect(route('login'));

        expect(session('url.intended'))->toBe(route('pair.show', 'K7QM2X'));
    });

    it('shows the TV that waits for the code and the screens the user may put on it', function () {
        $memberOf = Screen::factory()->create(['name' => 'Elsewhere']);
        $memberOf->team->members()->attach($this->admin, ['role' => TeamRole::Member->value]);

        $this->actingAs($this->admin)->get(route('pair.show', 'k7q-m2x'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('pair/show')
            ->where('code', 'K7Q-M2X')
            ->where('pairing.code', 'K7Q-M2X')
            ->where('pairing.device', 'Fire TV · Silk')
            ->where('pairing.slot', 1)
            ->where('pairing.next_url', route('tv', 2))
            ->has('screens', 1)
            ->where('screens.0.uuid', $this->screen->uuid)
            ->where('screens.0.team', $this->screen->team->name)
            ->where('paired', null));
    });

    it('finds no TV for a code that is unknown, expired or used', function (string $code) {
        TvPairing::factory()->expired()->create(['code' => 'EXP1RD']);
        TvPairing::factory()->confirmedFor(Display::factory()->create())->create(['code' => 'USED22']);

        $this->actingAs($this->admin)->get(route('pair.show', $code))->assertInertia(fn (Assert $page) => $page->where('pairing', null));
    })->with(['unknown' => 'ZZZZZZ', 'expired' => 'EXP1RD', 'used' => 'USED22']);

    it('asks for a code on /pair', function () {
        $this->actingAs($this->admin)->get(route('pair'))->assertInertia(fn (Assert $page) => $page
            ->component('pair/show')->where('code', null)->where('pairing', null));
    });
});

describe('confirming a code', function () {
    beforeEach(fn () => Event::fake([TvPairingConfirmed::class]));

    it('pairs the TV as a display of the screen\'s team and tells the TV', function () {
        $response = $this->actingAs($this->admin)->post(route('pair.confirm', 'K7QM2X'), ['screen' => $this->screen->uuid, 'name' => 'Office TV']);

        $display = Display::query()->sole();
        $response->assertRedirect(route('pair', ['paired' => $display->uuid]));
        expect($display->team_id)->toBe($this->screen->team_id)
            ->and($display->screen_id)->toBe($this->screen->id)
            ->and($display->user_agent)->toBe($this->pairing->user_agent)
            // The TV fetches its token itself, with its pairing cookie.
            ->and($display->token_hash)->toBeNull()
            ->and($this->pairing->refresh()->display_id)->toBe($display->id)
            ->and($this->pairing->confirmed_at)->not->toBeNull()
            ->and(AdminAuditEntry::query()->sole()->action)->toBe(AdminAction::DisplayPaired);
        Event::assertDispatched(TvPairingConfirmed::class, fn (TvPairingConfirmed $e): bool => $e->broadcastOn()->name === 'private-tv-pairing.'.$this->pairing->uuid
            && $e->broadcastAs() === 'pairing.confirmed' && $e->broadcastWith() === []);

        $this->get(route('pair', ['paired' => $display->uuid]))->assertInertia(fn (Assert $page) => $page->where('paired', ['display' => 'Office TV', 'screen' => 'Ops', 'slot' => 1, 'next_url' => route('tv', 2)]));
        // Only for the team's members.
        $this->actingAs(User::factory()->inTeam()->create())->get(route('pair', ['paired' => $display->uuid]))->assertInertia(fn (Assert $page) => $page->where('paired', null));
        $this->get(route('pair', ['paired' => 'not-a-uuid']))->assertInertia(fn (Assert $page) => $page->where('paired', null));
    });

    it('pairs a window of a computer as that window, offering no window past the last', function () {
        $this->pairing->update(['slot' => 9, 'device_hash' => str_repeat('a', 64)]);

        $this->actingAs($this->admin)->get(route('pair.show', 'K7QM2X'))->assertInertia(fn (Assert $page) => $page
            ->where('pairing.slot', 9)->where('pairing.next_url', null));
        $this->actingAs($this->admin)->post(route('pair.confirm', 'K7QM2X'), ['screen' => $this->screen->uuid, 'name' => 'Office PC 9']);

        expect(Display::query()->sole()->only('slot', 'device_hash'))->toBe(['slot' => 9, 'device_hash' => str_repeat('a', 64)]);
    });

    it('works once', function () {
        $this->actingAs($this->admin)->post(route('pair.confirm', 'K7QM2X'), ['screen' => $this->screen->uuid, 'name' => 'Office TV']);
        $this->actingAs($this->admin)->post(route('pair.confirm', 'K7QM2X'), ['screen' => $this->screen->uuid, 'name' => 'Again'])
            ->assertSessionHasErrors('code');

        expect(Display::query()->count())->toBe(1);
    });

    it('refuses an expired code', function () {
        $this->pairing->update(['expires_at' => now()->subSecond()]);

        $this->actingAs($this->admin)->post(route('pair.confirm', 'K7QM2X'), ['screen' => $this->screen->uuid, 'name' => 'Office TV'])
            ->assertSessionHasErrors(['code' => __('screens.pairing.expired')]);
        Event::assertNotDispatched(TvPairingConfirmed::class);
    });

    it('lets only a team admin pair, with a screen of that team', function (string $who) {
        $user = match ($who) {
            'member' => User::factory()->inTeam($this->screen->team, TeamRole::Member)->create(),
            'outsider' => User::factory()->inTeam()->create(),
        };

        $this->actingAs($user)->post(route('pair.confirm', 'K7QM2X'), ['screen' => $this->screen->uuid, 'name' => 'Office TV'])
            ->assertSessionHasErrors('screen');
        expect(Display::query()->count())->toBe(0);
    })->with(['member', 'outsider']);

    it('needs a screen and a name', function () {
        $this->actingAs($this->admin)->post(route('pair.confirm', 'K7QM2X'), ['screen' => 'nope', 'name' => ''])
            ->assertSessionHasErrors(['screen', 'name']);
    });

    it('has the action refuse a user who does not administer the team', function () {
        $member = User::factory()->inTeam($this->screen->team, TeamRole::Member)->create();
        $action = app(ConfirmTvPairingAction::class);

        expect($action->execute($this->pairing, $member, $this->screen, 'TV'))->toBeNull()
            ->and($action->reasons)->toBe([__('screens.pairing.not_admin')]);
    });

    it('has the action refuse a code confirmed in the meantime', function () {
        $stale = TvPairing::query()->find($this->pairing->id);
        $this->pairing->update(['confirmed_at' => now()]);
        $action = app(ConfirmTvPairingAction::class);

        expect($action->execute($stale, $this->admin, $this->screen, 'TV'))->toBeNull()
            ->and($action->reasons)->toBe([__('screens.pairing.expired')])
            ->and(Display::query()->count())->toBe(0);
    });

    it('checks the session\'s CSRF token', function () {
        $route = Route::getRoutes()->getByName('pair.confirm');

        expect($route->gatherMiddleware())->toContain('web')
            ->and($route->excludedMiddleware())->not->toContain(PreventRequestForgery::class);
    });
});

describe('guessing codes', function () {
    it('is throttled per user', function () {
        foreach (range(1, 10) as $ignored) {
            $this->actingAs($this->admin)->get(route('pair.show', 'ZZZZZZ'))->assertOk();
        }

        $this->actingAs($this->admin)->get(route('pair.show', 'ZZZZZZ'))->assertTooManyRequests();
        $this->actingAs($this->admin)->post(route('pair.confirm', 'K7QM2X'), ['screen' => $this->screen->uuid, 'name' => 'TV'])->assertTooManyRequests();
    });

    it('is throttled per address, across users', function () {
        foreach (range(1, 3) as $ignored) {
            $user = User::factory()->create();

            foreach (range(1, 10) as $ignoredToo) {
                $this->actingAs($user)->get(route('pair.show', 'ZZZZZZ'))->assertOk();
            }
        }

        $this->actingAs(User::factory()->create())->get(route('pair.show', 'ZZZZZZ'))->assertTooManyRequests();
    });
});
