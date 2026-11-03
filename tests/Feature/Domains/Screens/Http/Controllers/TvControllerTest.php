<?php

declare(strict_types=1);

use App\Domains\Screens\Actions\ClaimTvPairingAction;
use App\Domains\Screens\Actions\ConfirmTvPairingAction;
use App\Domains\Screens\Actions\StartTvPairingAction;
use App\Domains\Screens\Actions\TouchDisplayAction;
use App\Domains\Screens\Enums\DisplayTheme;
use App\Domains\Screens\Events\DisplayUpdated;
use App\Domains\Screens\Http\Controllers\TvController;
use App\Domains\Screens\Http\Requests\RestoreDisplayRequest;
use App\Domains\Screens\Http\Requests\TvHeartbeatRequest;
use App\Domains\Screens\Http\Requests\ViewerDataRequest;
use App\Domains\Screens\Models\Device;
use App\Domains\Screens\Models\Display;
use App\Domains\Screens\Models\Screen;
use App\Domains\Screens\Models\TvPairing;
use App\Domains\Screens\Presenters\ScreenPresenter;
use App\Domains\Screens\Providers\ScreensServiceProvider;
use App\Domains\Screens\Support\TvCredentials;
use App\Domains\Teams\Enums\TeamRole;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;

covers(
    TvController::class,
    TvCredentials::class,
    StartTvPairingAction::class,
    ClaimTvPairingAction::class,
    DisplayUpdated::class,
    TouchDisplayAction::class,
    RestoreDisplayRequest::class,
    TvHeartbeatRequest::class,
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
        $response = ($this->tv)()->get(route('tv', 1))->assertOk()->assertInertia(fn (Assert $page) => $page
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
            ->where('display.name', 'Office TV, window 1')
            ->has('version'));

        // The cookie rolls: another year from today.
        expect($response->getCookie(TvCredentials::displayCookieName(1), decrypt: false)->getExpiresTime())->toBeGreaterThan(now()->addDays(364)->timestamp)
            ->and($this->display->refresh()->last_seen_at->timestamp)->toBe(now()->timestamp)
            // The token is nowhere in the page.
            ->and($response->getContent())->not->toContain('ljd_token');
    });

    it('draws the screen in the theme the display forces', function () {
        $this->display->update(['theme' => DisplayTheme::Light]);

        ($this->tv)()->get(route('tv', 1))->assertInertia(fn (Assert $page) => $page
            ->where('screens.0.screen.theme', 'light')
            ->where('screens.0.screen.current_theme', 'light'));
        expect($this->screen->refresh()->theme->value)->toBe('dark');
    });

    it('says so when it shows no screen', function () {
        $this->display->update(['screen_id' => null]);

        ($this->tv)()->get(route('tv', 1))->assertInertia(fn (Assert $page) => $page->component('tv/show')->where('screens', []));
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

        ($this->tv)()->get(route('tv', 1))->assertInertia(fn (Assert $page) => $page
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

    it('keeps the size its window reports, at once when it changed, and refuses nonsense', function () {
        $this->display->update(['last_seen_at' => now()->subSeconds(10)]);

        ($this->tv)()->getJson(route('tv.heartbeat', [1, 'w' => 1920, 'h' => 1080, 'dpr' => 2]))->assertOk();
        ($this->tv)()->getJson(route('tv.heartbeat', [1, 'w' => 5]))->assertUnprocessable();

        expect([$this->display->refresh()->viewport_width, $this->display->viewport_height, $this->display->pixel_ratio])->toBe([1920, 1080, 2.0]);
    });

    it('keeps its device\'s last seen, browser and address current', function () {
        $this->display->device->update(['last_seen_at' => now()->subHour(), 'user_agent' => 'old', 'ip' => '10.0.0.1']);

        ($this->tv)()->withHeader('User-Agent', 'Chromium kiosk')->getJson(route('tv.heartbeat', 1))->assertOk();

        expect($this->display->device->refresh()->only('user_agent', 'ip'))->toBe(['user_agent' => 'Chromium kiosk', 'ip' => '127.0.0.1'])
            ->and($this->display->device->last_seen_at->timestamp)->toBe(now()->timestamp);
    });

    it('tells the team\'s pages of its new size at once, but not on every step of a drag', function () {
        Event::fake([DisplayUpdated::class]);
        $this->display->update(['last_seen_at' => now()->subSeconds(10)]);

        ($this->tv)()->getJson(route('tv.heartbeat', [1, 'w' => 1280, 'h' => 720, 'dpr' => 1]))->assertOk();

        Event::assertDispatched(DisplayUpdated::class, fn (DisplayUpdated $e): bool => $e->broadcastAs() === 'display.updated'
            && $e->broadcastOn()->name === 'private-team.'.$this->display->team->uuid.'.displays'
            && $e->broadcastWith() === [
                'uuid' => $this->display->uuid,
                'device' => $this->display->device->uuid,
                'name' => 'Office TV, window 1',
                'viewport' => ['w' => 1280, 'h' => 720],
                'last_seen_at' => now()->toIso8601String(),
            ]);

        foreach (range(1, TouchDisplayAction::RESIZES_PER_MINUTE + 5) as $width) {
            ($this->tv)()->getJson(route('tv.heartbeat', [1, 'w' => 1300 + $width, 'h' => 720, 'dpr' => 1]))->assertOk();
        }

        Event::assertDispatchedTimes(DisplayUpdated::class, TouchDisplayAction::RESIZES_PER_MINUTE);
        // Nothing new to say, nothing sent.
        ($this->tv)()->getJson(route('tv.heartbeat', 1))->assertOk();
        Event::assertDispatchedTimes(DisplayUpdated::class, TouchDisplayAction::RESIZES_PER_MINUTE);
    });

    it('takes the window its cookie is in as its window number', function () {
        $this->display->update(['slot' => 2, 'last_seen_at' => now()]);

        ($this->tv)()->getJson(route('tv.heartbeat', 1))->assertOk();

        expect($this->display->refresh()->slot)->toBe(1);
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
        $this->withCookie(TvCredentials::displayCookieName(1), 'ljd_one')->get(route('tv', 1))->assertInertia(fn (Assert $page) => $page
            ->where('display.label', 'Window one')
            ->where('display.name', 'Office TV, Window one'));
    });

    it('knows windows 1 to 9 only', function () {
        $this->get('/tv/0')->assertNotFound();
        $this->get('/tv/10')->assertNotFound();
    });
});

describe('choosing a window at /tv', function () {
    beforeEach(function () {
        $this->device = Device::factory()->create(['name' => 'Office PC']);
        $ops = Screen::factory()->for($this->device->team)->create(['name' => 'Ops']);
        $shop = Screen::factory()->for($this->device->team)->create(['name' => 'Shop on-call']);
        Display::factory()->showing($ops)->onDevice($this->device, 1)->withToken('ljd_one')->create();
        Display::factory()->showing($shop)->onDevice($this->device, 2)->withToken('ljd_two')->create(['playlist' => [$shop->id, $ops->id]]);
        $this->both = fn () => $this->withCookie(TvCredentials::displayCookieName(1), 'ljd_one')->withCookie(TvCredentials::displayCookieName(2), 'ljd_two');
    });

    it('lets a browser with several windows pick which this one shows, or add another', function () {
        ($this->both)()->get(route('tv'))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertInertia(fn (Assert $page) => $page
            ->component('tv/choose')
            ->where('device', 'Office PC')
            ->where('windows', [
                ['slot' => 1, 'label' => 'Window 1', 'screens' => ['Ops'], 'url' => route('tv', 1)],
                ['slot' => 2, 'label' => 'Window 2', 'screens' => ['Shop on-call', 'Ops'], 'url' => route('tv', 2)],
            ])
            ->where('add', ['slot' => 3, 'url' => route('tv', 3)]));

        expect(TvPairing::query()->count())->toBe(0);
    });

    it('keeps /tv/{slot} as a direct address', function () {
        ($this->both)()->get(route('tv', 2))->assertInertia(fn (Assert $page) => $page->component('tv/show')->where('slot', 2));
    });

    it('lands on the chooser with one window too, never forwarding by itself', function () {
        $this->withCookie(TvCredentials::displayCookieName(2), 'ljd_two')->get(route('tv'))->assertInertia(fn (Assert $page) => $page
            ->component('tv/choose')
            ->where('windows.0.url', route('tv', 2))
            ->where('add', ['slot' => 1, 'url' => route('tv', 1)]));
    });

    it('ignores cookies of windows that are no longer paired', function () {
        $this->withCookie(TvCredentials::displayCookieName(1), 'ljd_one')->withCookie(TvCredentials::displayCookieName(3), 'ljd_gone')->get(route('tv'))
            ->assertInertia(fn (Assert $page) => $page->component('tv/choose')->has('windows', 1)->where('add.slot', 2));
    });

    it('starts first setup as window 1 on a browser with none paired, which then lives at /tv/1', function () {
        $this->withCookie(TvCredentials::displayCookieName(1), 'ljd_gone')->get(route('tv'))
            ->assertInertia(fn (Assert $page) => $page->component('tv/pair')->where('slot', 1)->where('tv_url', route('tv', 1)));
    });

    it('pairs an added window into the same device', function () {
        $admin = User::factory()->inTeam($this->device->team, TeamRole::Admin)->create();
        $this->device->update(['device_hash' => TvCredentials::hash('device-cookie')]);

        ($this->both)()->withCookie(TvCredentials::DEVICE_COOKIE, 'device-cookie')->get(route('tv', 3))
            ->assertInertia(fn (Assert $page) => $page->component('tv/pair')->where('slot', 3));
        $pairing = TvPairing::query()->sole();
        expect($pairing->device_hash)->toBe($this->device->device_hash);

        $display = app(ConfirmTvPairingAction::class)->execute($pairing, $admin, Screen::query()->where('name', 'Ops')->sole());

        expect($display->device_id)->toBe($this->device->id)
            ->and($display->slot)->toBe(3)
            ->and($this->device->displays()->count())->toBe(3);
    });
});
