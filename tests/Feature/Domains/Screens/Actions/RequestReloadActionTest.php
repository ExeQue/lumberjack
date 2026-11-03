<?php

declare(strict_types=1);

use App\Domains\Screens\Actions\RequestReloadAction;
use App\Domains\Screens\Events\ReloadRequested;
use App\Domains\Screens\Exceptions\ReloadTooSoonException;
use App\Domains\Screens\Http\Controllers\DisplayController;
use App\Domains\Screens\Http\Controllers\ScreenController;
use App\Domains\Screens\Models\Display;
use App\Domains\Screens\Models\Screen;
use App\Domains\Teams\Enums\TeamRole;
use App\Models\User;
use Illuminate\Support\Facades\Event;

covers(RequestReloadAction::class, ReloadRequested::class, ReloadTooSoonException::class, ScreenController::class, DisplayController::class);

beforeEach(function () {
    Event::fake([ReloadRequested::class]);
    $this->screen = Screen::factory()->create();
    $this->display = Display::factory()->showing($this->screen)->create(['name' => 'Hall']);
    $this->admin = User::factory()->inTeam($this->screen->team, TeamRole::Admin)->create();
    $this->member = User::factory()->inTeam($this->screen->team, TeamRole::Member)->create();
});

describe('reloading what shows a screen', function () {
    it('tells the screen\'s channel, once every few seconds', function () {
        $this->actingAs($this->member)->postJson(route('screens.reload', $this->screen))->assertOk()->assertJsonStructure(['count', 'message']);
        $this->actingAs($this->member)->postJson(route('screens.reload', $this->screen))->assertTooManyRequests()->assertHeader('Retry-After');

        Event::assertDispatchedTimes(ReloadRequested::class, 1);
        Event::assertDispatched(ReloadRequested::class, fn (ReloadRequested $e): bool => $e->broadcastAs() === 'screen.reload'
            && $e->broadcastOn()[0]->name === 'private-screen.'.$this->screen->uuid);
    });

    it('keeps other teams out', function () {
        $this->actingAs(User::factory()->create())->postJson(route('screens.reload', $this->screen))->assertForbidden();

        Event::assertNotDispatched(ReloadRequested::class);
    });
});

describe('reloading one display', function () {
    it('tells the display\'s channel, for team admins only', function () {
        $this->actingAs($this->member)->post(route('displays.reload', $this->display))->assertForbidden();
        $this->actingAs($this->admin)->post(route('displays.reload', $this->display))->assertSessionHas('status', 'Reload sent to Hall.');
        $this->actingAs($this->admin)->post(route('displays.reload', $this->display))->assertSessionHas('status', fn (string $s): bool => str_starts_with($s, 'A reload went out moments ago'));

        Event::assertDispatchedTimes(ReloadRequested::class, 1);
        Event::assertDispatched(ReloadRequested::class, fn (ReloadRequested $e): bool => $e->broadcastAs() === 'display.reload');
    });
});
