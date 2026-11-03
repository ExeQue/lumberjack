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
    it('shows the team\'s displays to every member, read only for members', function () {
        Display::factory()->offline()->for($this->screen->team)->create(['name' => 'Kitchen', 'user_agent' => null]);
        Display::factory()->create();

        $this->actingAs($this->member)->get(route('team.displays'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('team/displays')
            ->has('displays', 2)
            ->where('displays.0.name', 'Kitchen')
            ->where('displays.0.online', false)
            ->where('displays.0.device', null)
            ->where('displays.0.screen', null)
            ->where('displays.1.name', 'Office TV')
            ->where('displays.1.online', true)
            ->where('displays.1.device', 'Fire TV · Silk')
            ->where('displays.1.screen', $this->screen->uuid)
            ->where('displays.1.theme', 'default')
            ->has('screens', 2)
            ->where('themes', ['default', 'dark', 'light', 'schedule'])
            ->where('can_manage', false)
            ->where('tv.url', route('tv')));

        $this->actingAs($this->admin)->get(route('team.displays'))->assertInertia(fn (Assert $page) => $page->where('can_manage', true));
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
        Event::assertDispatched(DisplayChanged::class, fn (DisplayChanged $e): bool => $e->broadcastAs() === 'display.changed'
            && $e->broadcastOn()->name === 'private-display.'.$this->display->uuid && $e->broadcastWith() === []);
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
    it('stops its token at once and sends the TV back to pairing', function () {
        Event::fake([DisplayChanged::class]);

        $this->actingAs($this->admin)->delete(route('displays.destroy', $this->display))
            ->assertRedirect()->assertSessionHas('status', __('screens.displays.removed'));

        expect(Display::query()->count())->toBe(0)
            ->and(AdminAuditEntry::query()->sole()->action)->toBe(AdminAction::DisplayRemoved);
        Event::assertDispatched(DisplayChanged::class, fn (DisplayChanged $e): bool => $e->broadcastAs() === 'display.removed');

        auth()->logout();
        $this->withCredentials()->withCookie(TvCredentials::DISPLAY_COOKIE, 'ljd_token')->getJson(route('tv.data'))->assertUnauthorized();
        $this->withCookie(TvCredentials::DISPLAY_COOKIE, 'ljd_token')->get(route('tv'))->assertInertia(fn (Assert $page) => $page->component('tv/pair'));
    });
});
