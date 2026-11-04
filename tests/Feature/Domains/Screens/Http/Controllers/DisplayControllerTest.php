<?php

declare(strict_types=1);

use App\Domains\Administration\Enums\AdminAction;
use App\Domains\Administration\Models\AdminAuditEntry;
use App\Domains\Screens\Actions\RemoveDisplayAction;
use App\Domains\Screens\Actions\UpdateDisplayAction;
use App\Domains\Screens\Enums\DisplayTheme;
use App\Domains\Screens\Events\DisplayChanged;
use App\Domains\Screens\Http\Controllers\DisplayController;
use App\Domains\Screens\Http\Requests\UpdateDisplayRequest;
use App\Domains\Screens\Models\Device;
use App\Domains\Screens\Models\Display;
use App\Domains\Screens\Models\Screen;
use App\Domains\Screens\Policies\DisplayPolicy;
use App\Domains\Screens\Support\TvCredentials;
use App\Domains\Teams\Enums\TeamRole;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;

covers(
    DisplayController::class,
    DisplayPolicy::class,
    UpdateDisplayRequest::class,
    UpdateDisplayAction::class,
    RemoveDisplayAction::class,
    DisplayChanged::class,
);

beforeEach(function () {
    $this->screen = Screen::factory()->create(['name' => 'Ops']);
    $this->other = Screen::factory()->for($this->screen->team)->create(['name' => 'Shop']);
    $this->display = Display::factory()->showing($this->screen)->withToken('ljd_token')->create();
    $this->admin = User::factory()->inTeam($this->screen->team, TeamRole::Admin)->create();
    $this->member = User::factory()->inTeam($this->screen->team, TeamRole::Member)->create();
});

describe('the list of displays', function () {
    it('shows the team\'s devices with their windows to every member, read only for members', function () {
        $kitchen = Device::factory()->for($this->screen->team)->create(['name' => 'Kitchen', 'user_agent' => null, 'last_seen_at' => now()->subDays(3)]);
        Display::factory()->offline()->onDevice($kitchen)->create(['viewport_width' => 1920, 'viewport_height' => 1080]);
        Display::factory()->create();

        $this->actingAs($this->member)->get(route('team.displays'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('team/displays')
            ->has('devices', 2)
            ->where('devices.0.name', 'Kitchen')
            ->where('devices.0.online', false)
            ->where('devices.0.browser', null)
            ->where('devices.0.next_url', route('tv', 2))
            ->where('devices.0.windows.0.label', 'Window 1')
            ->where('devices.0.windows.0.screen', null)
            ->where('devices.0.windows.0.viewport', ['w' => 1920, 'h' => 1080])
            ->where('devices.1.name', 'Office TV')
            ->where('devices.1.online', true)
            ->where('devices.1.browser', 'Fire TV · Silk')
            ->where('devices.1.windows.0.screen', $this->screen->uuid)
            ->where('devices.1.windows.0.theme', 'default')
            ->where('devices.1.windows.0.viewport', null)
            ->where('devices.1.windows.0.online', true)
            ->has('screens', 2)
            ->where('themes', ['default', 'dark', 'light', 'schedule'])
            ->where('can_manage', false)
            ->where('channel', 'team.'.$this->screen->team->uuid.'.displays')
            ->where('tv.url', route('tv')));

        $this->actingAs($this->admin)->get(route('team.displays'))->assertInertia(fn (Assert $page) => $page->where('can_manage', true));
    });

    it('lists the windows of a device under it, in window order, with the next free window to add', function () {
        $device = $this->display->device;
        Display::factory()->onDevice($device, 3)->create(['name' => 'Kiosk']);
        Display::factory()->offline()->onDevice($device, 2)->create();

        $this->actingAs($this->member)->get(route('team.displays'))->assertInertia(fn (Assert $page) => $page
            ->has('devices', 1)
            ->where('devices.0.online', true)
            ->where('devices.0.next_url', route('tv', 4))
            ->has('devices.0.windows', 3)
            ->where('devices.0.windows.0.slot', 1)
            ->where('devices.0.windows.1.label', 'Window 2')
            ->where('devices.0.windows.1.online', false)
            ->where('devices.0.windows.2.label', 'Kiosk')
            ->where('devices.0.windows.2.name', 'Kiosk')
            ->where('devices.0.windows.0.playlist', []));
    });

    it('sends someone without a team to the team page', function () {
        $this->actingAs(User::factory()->create())->get(route('team.displays'))->assertRedirect(route('team.show'));
    });
});

describe('changing a display', function () {
    beforeEach(fn () => Event::fake([DisplayChanged::class]));

    it('switches the screen and theme it shows, and tells the TV', function () {
        $this->actingAs($this->admin)->put(route('displays.update', $this->display), ['screen' => $this->other->uuid, 'theme' => 'light', 'name' => 'Lobby'])
            ->assertRedirect()->assertSessionHas('status', __('screens.displays.saved'));

        $display = $this->display->refresh();
        expect($display->screen_id)->toBe($this->other->id)
            ->and($display->theme)->toBe(DisplayTheme::Light)
            ->and($display->name)->toBe('Lobby');

        $this->actingAs($this->admin)->put(route('displays.update', $this->display), ['name' => ''])->assertRedirect();
        expect($this->display->refresh()->name)->toBeNull()->and($this->display->label())->toBe('Window 1');
        Event::assertDispatched(DisplayChanged::class, fn (DisplayChanged $e): bool => $e->broadcastAs() === 'display.changed'
            && $e->broadcastOn()->name === 'private-display.'.$this->display->uuid && $e->broadcastWith() === []);
    });

    it('turns the picture of a TV hung sideways, a quarter at a time', function () {
        $this->actingAs($this->admin)->put(route('displays.update', $this->display), ['rotation' => 90])->assertRedirect();
        expect($this->display->refresh()->rotation)->toBe(90);

        $this->actingAs($this->admin)->put(route('displays.update', $this->display), ['rotation' => 45])->assertSessionHasErrors('rotation');
        expect($this->display->refresh()->rotation)->toBe(90);
    });

    it('shows several screens in turn, its team\'s only, and one again from the single pick', function () {
        $this->display->update(['theme' => DisplayTheme::Dark]);

        $this->actingAs($this->admin)->put(route('displays.update', $this->display), [
            'playlist' => [$this->other->uuid, $this->screen->uuid], 'rotate_seconds' => 45, 'hold_on_alert' => false,
        ])->assertRedirect();

        $display = $this->display->refresh();
        expect($display->playlist)->toBe([$this->other->id, $this->screen->id])
            ->and($display->screen_id)->toBe($this->other->id)
            ->and($display->rotate_seconds)->toBe(45)
            ->and($display->hold_on_alert)->toBeFalse();
        $this->actingAs($this->admin)->get(route('team.displays'))->assertInertia(fn (Assert $page) => $page
            ->where('devices.0.windows.0.playlist', [$this->other->uuid, $this->screen->uuid]));

        $this->actingAs($this->admin)->put(route('displays.update', $this->display), ['screen' => $this->screen->uuid])->assertRedirect();
        expect($this->display->refresh()->playlist)->toBeNull()->and($this->display->screen_id)->toBe($this->screen->id);
    });

    it('takes a playlist of one as that one screen, and leaves out screens of other teams', function () {
        $foreign = Screen::factory()->create();

        app(UpdateDisplayAction::class)->execute($this->display, ['playlist' => [$foreign->id, $this->other->id]]);

        expect($this->display->refresh()->playlist)->toBeNull()->and($this->display->screen_id)->toBe($this->other->id);
    });

    it('refuses a playlist with a screen of another team, or a too short time', function () {
        $this->actingAs($this->admin)->put(route('displays.update', $this->display), ['playlist' => [Screen::factory()->create()->uuid]])->assertSessionHasErrors('playlist.0');
        $this->actingAs($this->admin)->put(route('displays.update', $this->display), ['rotate_seconds' => 3])->assertSessionHasErrors('rotate_seconds');
    });

    it('shows no screen at all', function () {
        $this->actingAs($this->admin)->put(route('displays.update', $this->display), ['screen' => null])->assertRedirect();

        expect($this->display->refresh()->screen_id)->toBeNull();
    });

    it('refuses a screen of another team', function () {
        $foreign = Screen::factory()->create();

        $this->actingAs($this->admin)->put(route('displays.update', $this->display), ['screen' => $foreign->uuid])->assertSessionHasErrors('screen');

        expect($this->display->refresh()->screen_id)->toBe($this->screen->id);
    });

    it('has the action keep a screen of another team out too', function () {
        $foreign = Screen::factory()->create();

        app(UpdateDisplayAction::class)->execute($this->display, ['screen_id' => $foreign->id]);

        expect($this->display->refresh()->screen_id)->toBe($this->screen->id);
        Event::assertNotDispatched(DisplayChanged::class);
    });

    it('is for team admins', function (string $who) {
        $user = $who === 'member' ? $this->member : User::factory()->inTeam()->create();

        $this->actingAs($user)->put(route('displays.update', $this->display), ['theme' => 'light'])->assertForbidden();
        $this->actingAs($user)->delete(route('displays.destroy', $this->display))->assertForbidden();
    })->with(['member', 'outsider']);
});

describe('removing a display', function () {
    it('stops its token at once and sends the TV back to pairing, and its device with its last window', function () {
        Event::fake([DisplayChanged::class]);

        $this->actingAs($this->admin)->delete(route('displays.destroy', $this->display))
            ->assertRedirect()->assertSessionHas('status', __('screens.displays.removed'));

        expect(Display::query()->count())->toBe(0)
            ->and(Device::query()->count())->toBe(0)
            ->and(AdminAuditEntry::query()->sole()->action)->toBe(AdminAction::DisplayRemoved)
            ->and(AdminAuditEntry::query()->sole()->subject)->toBe('Office TV, window 1');
        Event::assertDispatched(DisplayChanged::class, fn (DisplayChanged $e): bool => $e->broadcastAs() === 'display.removed');

        auth()->logout();
        $this->withCredentials()->withCookie(TvCredentials::displayCookieName(1), 'ljd_token')->getJson(route('tv.data', 1))->assertUnauthorized();
        $this->withCookie(TvCredentials::displayCookieName(1), 'ljd_token')->get(route('tv', 1))->assertInertia(fn (Assert $page) => $page->component('tv/pair'));
    });

    it('keeps the device while it has other windows', function () {
        $second = Display::factory()->onDevice($this->display->device, 2)->create();

        $this->actingAs($this->admin)->delete(route('displays.destroy', $second))->assertRedirect();

        expect(Display::query()->sole()->is($this->display))->toBeTrue()
            ->and(Device::query()->sole()->is($this->display->device))->toBeTrue();
    });
});
