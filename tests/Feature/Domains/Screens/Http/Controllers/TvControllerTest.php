<?php

declare(strict_types=1);

use App\Domains\Screens\Actions\ClaimTvPairingAction;
use App\Domains\Screens\Actions\StartTvPairingAction;
use App\Domains\Screens\Actions\TouchDisplayAction;
use App\Domains\Screens\Enums\DisplayTheme;
use App\Domains\Screens\Http\Controllers\TvController;
use App\Domains\Screens\Http\Requests\RestoreDisplayRequest;
use App\Domains\Screens\Http\Requests\ViewerDataRequest;
use App\Domains\Screens\Models\Display;
use App\Domains\Screens\Models\Screen;
use App\Domains\Screens\Models\TvPairing;
use App\Domains\Screens\Presenters\ScreenPresenter;
use App\Domains\Screens\Providers\ScreensServiceProvider;
use App\Domains\Screens\Support\TvCredentials;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

covers(
    TvController::class,
    TvCredentials::class,
    StartTvPairingAction::class,
    ClaimTvPairingAction::class,
    TouchDisplayAction::class,
    RestoreDisplayRequest::class,
    ViewerDataRequest::class,
    ScreenPresenter::class,
    ScreensServiceProvider::class,
);

// Someone exists, so setup is done; the TV itself is not signed in. JSON requests carry
// cookies, as the TV's fetch calls do.
beforeEach(function () {
    $this->freezeSecond();
    User::factory()->create();
    $this->withCredentials();
});

describe('a TV that is not paired', function () {
    it('shows a code to confirm, bound to an httpOnly pairing cookie', function () {
        $response = $this->get(route('tv'))->assertOk()->assertHeader('Cache-Control', 'no-store, private');

        $pairing = TvPairing::query()->sole();
        $cookie = $response->getCookie(TvCredentials::pairingCookieName(1), decrypt: false);
        expect($cookie->isHttpOnly())->toBeTrue()
            ->and(TvCredentials::hash($response->getCookie(TvCredentials::pairingCookieName(1))->getValue()))->toBe($pairing->secret_hash)
            ->and($pairing->code)->toMatch('/^[2-9A-HJ-NP-Z]{6}$/')
            ->and($pairing->expires_at->timestamp)->toBe(now()->addMinutes(10)->timestamp);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('tv/pair')
            ->where('code', $pairing->formattedCode())
            ->where('pair_url', route('pair.show', $pairing->code))
            ->where('expires_in', 600)
            // The channel is named by the pairing's uuid, never its code.
            ->where('channel', 'tv-pairing.'.$pairing->uuid)
            ->where('qr', fn (string $svg): bool => str_starts_with($svg, '<svg')));
    });

    it('keeps its code while it is pending, and gets a new one once it expired', function () {
        $pending = TvPairing::factory()->withSecret('secret-a')->create();
        $expired = TvPairing::factory()->withSecret('secret-b')->expired()->create();

        $this->withCookie(TvCredentials::pairingCookieName(1), 'secret-a')->get(route('tv'))
            ->assertInertia(fn (Assert $page) => $page->where('code', $pending->formattedCode()))
            ->assertCookieMissing(TvCredentials::pairingCookieName(1));

        $this->withCookie(TvCredentials::pairingCookieName(1), 'secret-b')->get(route('tv'))
            ->assertCookie(TvCredentials::pairingCookieName(1));

        expect(TvPairing::query()->whereKey($expired->id)->exists())->toBeFalse()
            ->and(TvPairing::query()->count())->toBe(2);
    });
});

describe('learning that the code was confirmed', function () {
    beforeEach(function () {
        $this->display = Display::factory()->create(['token_hash' => null, 'last_seen_at' => null]);
    });

    it('waits while nobody confirmed it', function () {
        TvPairing::factory()->withSecret('secret')->create();

        $this->withCookie(TvCredentials::pairingCookieName(1), 'secret')->postJson(route('tv.claim', 1))->assertExactJson(['status' => 'waiting']);
    });

    it('says expired without a pairing, or when the code expired', function (bool $cookie) {
        TvPairing::factory()->withSecret('secret')->confirmedFor($this->display)->expired()->create();

        $request = $cookie ? $this->withCookie(TvCredentials::pairingCookieName(1), 'secret') : $this;
        $request->postJson(route('tv.claim', 1))->assertExactJson(['status' => 'expired']);
    })->with(['no cookie' => false, 'expired code' => true]);

    it('hands the TV its display token once, as an httpOnly cookie and for local storage', function () {
        TvPairing::factory()->withSecret('secret')->confirmedFor($this->display)->create();

        $response = $this->withCookie(TvCredentials::pairingCookieName(1), 'secret')->postJson(route('tv.claim', 1))
            ->assertOk()->assertJsonPath('status', 'paired');

        $token = $response->json('token');
        $cookie = $response->getCookie(TvCredentials::displayCookieName(1), decrypt: false);
        expect($token)->toStartWith('ljd_')
            ->and($response->getCookie(TvCredentials::displayCookieName(1))->getValue())->toBe($token)
            ->and($cookie->isHttpOnly())->toBeTrue()
            ->and($cookie->getExpiresTime())->toBeGreaterThan(now()->addDays(364)->timestamp)
            // Only the hash is kept.
            ->and($this->display->refresh()->token_hash)->toBe(hash('sha256', $token))
            ->and(TvPairing::query()->count())->toBe(0);
        expect($response->getCookie(TvCredentials::pairingCookieName(1), decrypt: false)->getExpiresTime())->toBeLessThan(now()->timestamp);

        // Single use: asking again gets nothing.
        $this->withCookie(TvCredentials::pairingCookieName(1), 'secret')->postJson(route('tv.claim', 1))->assertExactJson(['status' => 'expired']);
    });

    it('says expired when the token was taken in the meantime', function () {
        TvPairing::factory()->withSecret('secret')->confirmedFor($this->display)->create();
        $this->mock(ClaimTvPairingAction::class)->shouldReceive('execute')->once()->andReturnNull();

        $this->withCookie(TvCredentials::pairingCookieName(1), 'secret')->postJson(route('tv.claim', 1))->assertExactJson(['status' => 'expired']);
    });

    it('gives no token for a pairing whose display is gone', function () {
        $pairing = TvPairing::factory()->confirmedFor($this->display)->create();
        $this->display->delete();

        expect(app(ClaimTvPairingAction::class)->execute($pairing))->toBeNull();
    });
});

describe('a paired TV', function () {
    beforeEach(function () {
        $this->screen = Screen::factory()->create();
        $this->display = Display::factory()->showing($this->screen)->withToken('ljd_token')->create(['last_seen_at' => now()->subHour()]);
        $this->tv = fn () => $this->withCookie(TvCredentials::displayCookieName(1), 'ljd_token');
    });

    it('shows its screen in the kiosk, reading data and changes as a display', function () {
        $response = ($this->tv)()->get(route('tv'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('tv/show')
            ->where('display.uuid', $this->display->uuid)
            ->where('display_channel', 'display.'.$this->display->uuid)
            ->where('slot', 1)
            ->has('screens', 1)
            ->where('screens.0.screen.uuid', $this->screen->uuid)
            ->where('screens.0.screen.theme', 'dark')
            ->where('screens.0.data_url', route('tv.data', ['slot' => 1, 'screen' => $this->screen->uuid], absolute: false))
            ->where('screens.0.channel', 'screen.'.$this->screen->uuid)
            ->where('screens.0.share', false)
            ->where('rotate_seconds', 30)
            ->where('hold_on_alert', true)
            ->where('urls.heartbeat', '/tv/1/heartbeat')
            ->has('version'));

        // The cookie rolls: another year from today.
        expect($response->getCookie(TvCredentials::displayCookieName(1), decrypt: false)->getExpiresTime())->toBeGreaterThan(now()->addDays(364)->timestamp)
            ->and($this->display->refresh()->last_seen_at->timestamp)->toBe(now()->timestamp)
            // The token is nowhere in the page.
            ->and($response->getContent())->not->toContain('ljd_token');
    });

    it('draws the screen in the theme the display forces', function () {
        $this->display->update(['theme' => DisplayTheme::Light]);

        ($this->tv)()->get(route('tv'))->assertInertia(fn (Assert $page) => $page
            ->where('screens.0.screen.theme', 'light')
            ->where('screens.0.screen.current_theme', 'light'));
        expect($this->screen->refresh()->theme->value)->toBe('dark');
    });

    it('says so when it shows no screen', function () {
        $this->display->update(['screen_id' => null]);

        ($this->tv)()->get(route('tv'))->assertInertia(fn (Assert $page) => $page->component('tv/show')->where('screens', []));
        ($this->tv)()->getJson(route('tv.data', 1))->assertOk()->assertJsonPath('data', []);
    });

    it('reads the data of its own screen only', function () {
        ($this->tv)()->getJson(route('tv.data', 1))->assertOk()->assertJsonStructure(['data' => ['requests', 'traffic'], 'at']);
        ($this->tv)()->getJson(route('tv.data', ['slot' => 1, 'widget' => 'requests']))->assertOk()->assertJsonStructure(['data' => ['requests']])->assertJsonMissingPath('data.traffic');
    });

    it('refuses data and heartbeats without a valid display token', function (?string $token) {
        $request = $token === null ? $this : $this->withCookie(TvCredentials::displayCookieName(1), $token);

        $request->getJson(route('tv.data', 1))->assertUnauthorized();
        $request->getJson(route('tv.heartbeat', 1))->assertUnauthorized();
    })->with(['no cookie' => null, 'a made up token' => 'ljd_guess', 'an empty one' => '']);

    it('shows several screens in turn, each with its own data, its team\'s only', function () {
        $second = Screen::factory()->for($this->screen->team)->create(['name' => 'Shop']);
        $foreign = Screen::factory()->create();
        $this->display->update(['playlist' => [$second->id, $foreign->id, $this->screen->id]]);

        ($this->tv)()->get(route('tv'))->assertInertia(fn (Assert $page) => $page
            ->has('screens', 2)
            ->where('screens.0.screen.uuid', $second->uuid)
            ->where('screens.1.screen.uuid', $this->screen->uuid));
        ($this->tv)()->getJson(route('tv.data', ['slot' => 1, 'screen' => $second->uuid]))->assertOk();
        ($this->tv)()->getJson(route('tv.data', ['slot' => 1, 'screen' => $foreign->uuid]))->assertNotFound();
        ($this->tv)()->getJson(route('tv.data', ['slot' => 1, 'screen' => 'nope']))->assertUnprocessable();
    });

    it('beats with what it should show, so a missed broadcast still reaches it', function () {
        $before = ($this->tv)()->getJson(route('tv.heartbeat', 1))->assertOk()->assertCookie(TvCredentials::displayCookieName(1))->json('version');
        $this->display->update(['theme' => DisplayTheme::Light]);

        expect(($this->tv)()->getJson(route('tv.heartbeat', 1))->json('version'))->not->toBe($before);
    });

    it('writes last seen at most once a minute', function () {
        $this->display->update(['last_seen_at' => now()->subSeconds(30)]);

        ($this->tv)()->getJson(route('tv.heartbeat', 1))->assertOk();

        expect($this->display->refresh()->last_seen_at->timestamp)->toBe(now()->subSeconds(30)->timestamp);
    });

    it('gets its cookie back from the token kept in local storage', function () {
        $response = $this->postJson(route('tv.restore', 1), ['token' => 'ljd_token'])->assertOk()->assertJsonPath('status', 'paired');

        expect($response->getCookie(TvCredentials::displayCookieName(1))->getValue())->toBe('ljd_token');
        $this->postJson(route('tv.restore', 1), ['token' => 'ljd_other'])->assertNotFound()->assertCookieMissing(TvCredentials::displayCookieName(1));
        $this->postJson(route('tv.restore', 1), [])->assertUnprocessable();
    });
});

describe('several windows of one computer', function () {
    it('pairs each window on its own, telling the computer by a device cookie', function () {
        $first = $this->get(route('tv'))->assertOk();
        $device = $first->getCookie(TvCredentials::DEVICE_COOKIE)->getValue();

        $this->withCookie(TvCredentials::DEVICE_COOKIE, $device)->get(route('tv', 2))->assertOk()
            ->assertCookie(TvCredentials::pairingCookieName(2))
            ->assertCookieMissing(TvCredentials::pairingCookieName(1))
            ->assertInertia(fn (Assert $page) => $page
                ->where('slot', 2)
                ->where('tv_url', route('tv', 2))
                ->where('urls.claim', '/tv/2/pairing'));

        $pairings = TvPairing::query()->orderBy('slot')->get();
        expect($pairings->pluck('slot')->all())->toBe([1, 2])
            ->and($pairings[0]->device_hash)->toBe(TvCredentials::hash($device))
            ->and($pairings[1]->device_hash)->toBe(TvCredentials::hash($device))
            ->and($first->getCookie(TvCredentials::DEVICE_COOKIE, decrypt: false)->isHttpOnly())->toBeTrue();
    });

    it('shows each window its own display, and claims the token into its own cookie', function () {
        $display = Display::factory()->create(['token_hash' => null, 'slot' => 2]);
        TvPairing::factory()->withSecret('secret-2')->confirmedFor($display)->create(['slot' => 2]);
        Display::factory()->withToken('ljd_one')->create(['name' => 'Window one']);

        $response = $this->withCookie(TvCredentials::pairingCookieName(2), 'secret-2')->postJson(route('tv.claim', 2))->assertJsonPath('status', 'paired');
        expect($response->getCookie(TvCredentials::displayCookieName(2))->getValue())->toBe($response->json('token'));

        $this->withCookie(TvCredentials::displayCookieName(1), 'ljd_one')->get(route('tv', 2))->assertInertia(fn (Assert $page) => $page->component('tv/pair'));
        $this->withCookie(TvCredentials::displayCookieName(1), 'ljd_one')->get(route('tv'))->assertInertia(fn (Assert $page) => $page->where('display.name', 'Window one'));
    });

    it('knows windows 1 to 9 only', function () {
        $this->get('/tv/0')->assertNotFound();
        $this->get('/tv/10')->assertNotFound();
    });
});
