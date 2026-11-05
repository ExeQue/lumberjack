<?php

declare(strict_types=1);

use App\Domains\Screens\Actions\IdentifyDisplayAction;
use App\Domains\Screens\Broadcasting\DisplayChannel;
use App\Domains\Screens\Events\DisplayIdentifyRequested;
use App\Domains\Screens\Http\Controllers\DisplayController;
use App\Domains\Screens\Models\Display;
use App\Domains\Screens\Models\Screen;
use App\Domains\Teams\Enums\TeamRole;
use App\Models\User;
use Illuminate\Support\Facades\Event;

covers(IdentifyDisplayAction::class, DisplayIdentifyRequested::class, DisplayController::class);

beforeEach(function () {
    Event::fake([DisplayIdentifyRequested::class]);
    $this->screen = Screen::factory()->create();
    $this->display = Display::factory()->showing($this->screen)->create(['name' => 'Hall']);
    $this->admin = User::factory()->inTeam($this->screen->team, TeamRole::Admin)->create();
    $this->member = User::factory()->inTeam($this->screen->team, TeamRole::Member)->create();
});

describe('identifying a display', function () {
    it('tells the display\'s own channel its name, for team admins only', function () {
        $this->actingAs($this->member)->post(route('displays.identify', $this->display))->assertForbidden();
        Event::assertNotDispatched(DisplayIdentifyRequested::class);

        $this->actingAs($this->admin)->post(route('displays.identify', $this->display))
            ->assertSessionHas('status', fn (string $status): bool => str_contains($status, $this->display->fullName()));

        Event::assertDispatched(DisplayIdentifyRequested::class, fn (DisplayIdentifyRequested $e): bool => $e->broadcastOn()[0]->name === DisplayChannel::for($this->display)->name
            && $e->broadcastAs() === 'display.identify'
            && $e->broadcastWith() === ['name' => $this->display->fullName()]);
    });
});
