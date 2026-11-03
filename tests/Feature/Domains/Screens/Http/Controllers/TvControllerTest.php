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
        $cookie = $response->getCookie(TvCredentials::PAIRING_COOKIE, decrypt: false);
        expect($cookie->isHttpOnly())->toBeTrue()
            ->and(TvCredentials::hash($response->getCookie(TvCredentials::PAIRING_COOKIE)->getValue()))->toBe($pairing->secret_hash)
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

        $this->withCookie(TvCredentials::PAIRING_COOKIE, 'secret-a')->get(route('tv'))
            ->assertInertia(fn (Assert $page) => $page->where('code', $pending->formattedCode()))
            ->assertCookieMissing(TvCredentials::PAIRING_COOKIE);

        $this->withCookie(TvCredentials::PAIRING_COOKIE, 'secret-b')->get(route('tv'))
            ->assertCookie(TvCredentials::PAIRING_COOKIE);

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

        $this->withCookie(TvCredentials::PAIRING_COOKIE, 'secret')->postJson(route('tv.pairing'))->assertExactJson(['status' => 'waiting']);
    });

    it('says expired without a pairing, or when the code expired', function (bool $cookie) {
        TvPairing::factory()->withSecret('secret')->confirmedFor($this->display)->expired()->create();

        $request = $cookie ? $this->withCookie(TvCredentials::PAIRING_COOKIE, 'secret') : $this;
        $request->postJson(route('tv.pairing'))->assertExactJson(['status' => 'expired']);
    })->with(['no cookie' => false, 'expired code' => true]);

    it('hands the TV its display token once, as an httpOnly cookie and for local storage', function () {
        TvPairing::factory()->withSecret('secret')->confirmedFor($this->display)->create();

        $response = $this->withCookie(TvCredentials::PAIRING_COOKIE, 'secret')->postJson(route('tv.pairing'))
            ->assertOk()->assertJsonPath('status', 'paired');

        $token = $response->json('token');
        $cookie = $response->getCookie(TvCredentials::DISPLAY_COOKIE, decrypt: false);
        expect($token)->toStartWith('ljd_')
            ->and($response->getCookie(TvCredentials::DISPLAY_COOKIE)->getValue())->toBe($token)
            ->and($cookie->isHttpOnly())->toBeTrue()
            ->and($cookie->getExpiresTime())->toBeGreaterThan(now()->addDays(364)->timestamp)
            // Only the hash is kept.
            ->and($this->display->refresh()->token_hash)->toBe(hash('sha256', $token))
            ->and(TvPairing::query()->count())->toBe(0);
        expect($response->getCookie(TvCredentials::PAIRING_COOKIE, decrypt: false)->getExpiresTime())->toBeLessThan(now()->timestamp);

        // Single use: asking again gets nothing.
        $this->withCookie(TvCredentials::PAIRING_COOKIE, 'secret')->postJson(route('tv.pairing'))->assertExactJson(['status' => 'expired']);
    });

    it('says expired when the token was taken in the meantime', function () {
        TvPairing::factory()->withSecret('secret')->confirmedFor($this->display)->create();
        $this->mock(ClaimTvPairingAction::class)->shouldReceive('execute')->once()->andReturnNull();

        $this->withCookie(TvCredentials::PAIRING_COOKIE, 'secret')->postJson(route('tv.pairing'))->assertExactJson(['status' => 'expired']);
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
        $this->tv = fn () => $this->withCookie(TvCredentials::DISPLAY_COOKIE, 'ljd_token');
    });

    it('shows its screen in the kiosk, reading data and changes as a display', function () {
        $response = ($this->tv)()->get(route('tv'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('tv/show')
            ->where('display.uuid', $this->display->uuid)
            ->where('display_channel', 'display.'.$this->display->uuid)
            ->where('screen.uuid', $this->screen->uuid)
            ->where('screen.theme', 'dark')
            ->where('data_url', route('tv.data', absolute: false))
            ->where('channel', 'screen.'.$this->screen->uuid)
            ->where('share', false)
            ->has('version'));

        // The cookie rolls: another year from today.
        expect($response->getCookie(TvCredentials::DISPLAY_COOKIE, decrypt: false)->getExpiresTime())->toBeGreaterThan(now()->addDays(364)->timestamp)
            ->and($this->display->refresh()->last_seen_at->timestamp)->toBe(now()->timestamp)
            // The token is nowhere in the page.
            ->and($response->getContent())->not->toContain('ljd_token');
    });

    it('draws the screen in the theme the display forces', function () {
        $this->display->update(['theme' => DisplayTheme::Light]);

        ($this->tv)()->get(route('tv'))->assertInertia(fn (Assert $page) => $page
            ->where('screen.theme', 'light')
            ->where('screen.current_theme', 'light'));
        expect($this->screen->refresh()->theme->value)->toBe('dark');
    });

    it('says so when it shows no screen', function () {
        $this->display->update(['screen_id' => null]);

        ($this->tv)()->get(route('tv'))->assertInertia(fn (Assert $page) => $page->component('tv/show')->where('screen', null));
        ($this->tv)()->getJson(route('tv.data'))->assertOk()->assertJsonPath('data', []);
    });

    it('reads the data of its own screen only', function () {
        ($this->tv)()->getJson(route('tv.data'))->assertOk()->assertJsonStructure(['data' => ['requests', 'traffic'], 'at']);
        ($this->tv)()->getJson(route('tv.data', ['widget' => 'requests']))->assertOk()->assertJsonStructure(['data' => ['requests']])->assertJsonMissingPath('data.traffic');
    });

    it('refuses data and heartbeats without a valid display token', function (?string $token) {
        $request = $token === null ? $this : $this->withCookie(TvCredentials::DISPLAY_COOKIE, $token);

        $request->getJson(route('tv.data'))->assertUnauthorized();
        $request->getJson(route('tv.heartbeat'))->assertUnauthorized();
    })->with(['no cookie' => null, 'a made up token' => 'ljd_guess', 'an empty one' => '']);

    it('beats with what it should show, so a missed broadcast still reaches it', function () {
        $before = ($this->tv)()->getJson(route('tv.heartbeat'))->assertOk()->assertCookie(TvCredentials::DISPLAY_COOKIE)->json('version');
        $this->display->update(['theme' => DisplayTheme::Light]);

        expect(($this->tv)()->getJson(route('tv.heartbeat'))->json('version'))->not->toBe($before);
    });

    it('writes last seen at most once a minute', function () {
        $this->display->update(['last_seen_at' => now()->subSeconds(30)]);

        ($this->tv)()->getJson(route('tv.heartbeat'))->assertOk();

        expect($this->display->refresh()->last_seen_at->timestamp)->toBe(now()->subSeconds(30)->timestamp);
    });

    it('gets its cookie back from the token kept in local storage', function () {
        $response = $this->postJson(route('tv.restore'), ['token' => 'ljd_token'])->assertOk()->assertJsonPath('status', 'paired');

        expect($response->getCookie(TvCredentials::DISPLAY_COOKIE)->getValue())->toBe('ljd_token');
        $this->postJson(route('tv.restore'), ['token' => 'ljd_other'])->assertNotFound()->assertCookieMissing(TvCredentials::DISPLAY_COOKIE);
        $this->postJson(route('tv.restore'), [])->assertUnprocessable();
    });
});
