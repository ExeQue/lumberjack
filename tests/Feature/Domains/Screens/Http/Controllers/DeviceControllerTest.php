<?php

declare(strict_types=1);

use App\Domains\Administration\Enums\AdminAction;
use App\Domains\Administration\Models\AdminAuditEntry;
use App\Domains\Screens\Actions\RemoveDeviceAction;
use App\Domains\Screens\Actions\RequestReloadAction;
use App\Domains\Screens\Actions\UpdateDeviceAction;
use App\Domains\Screens\Events\DisplayChanged;
use App\Domains\Screens\Events\ReloadRequested;
use App\Domains\Screens\Http\Controllers\DeviceController;
use App\Domains\Screens\Http\Requests\UpdateDeviceRequest;
use App\Domains\Screens\Models\Device;
use App\Domains\Screens\Models\Display;
use App\Domains\Screens\Models\TvPairing;
use App\Domains\Screens\Policies\DevicePolicy;
use App\Domains\Teams\Enums\TeamRole;
use App\Models\User;
use Illuminate\Support\Facades\Event;

covers(
    DeviceController::class,
    DevicePolicy::class,
    UpdateDeviceRequest::class,
    UpdateDeviceAction::class,
    RemoveDeviceAction::class,
    RequestReloadAction::class,
    ReloadRequested::class,
);

beforeEach(function () {
    $this->device = Device::factory()->create(['name' => 'Office PC']);
    $this->windows = collect([1, 2])->map(fn (int $slot): Display => Display::factory()->onDevice($this->device, $slot)->create());
    $this->admin = User::factory()->inTeam($this->device->team, TeamRole::Admin)->create();
    $this->member = User::factory()->inTeam($this->device->team, TeamRole::Member)->create();
});

describe('renaming a device', function () {
    it('takes a new name', function () {
        $this->actingAs($this->admin)->put(route('devices.update', $this->device), ['name' => ' Ops wall '])
            ->assertRedirect()->assertSessionHas('status', __('screens.displays.device_saved'));

        expect($this->device->refresh()->name)->toBe('Ops wall');
    });

    it('needs a name', function () {
        $this->actingAs($this->admin)->put(route('devices.update', $this->device), ['name' => ''])->assertSessionHasErrors('name');
    });
});

describe('reloading a device', function () {
    it('reaches every window of it, once every few seconds', function () {
        Event::fake([ReloadRequested::class]);
        $other = Display::factory()->create();

        $this->actingAs($this->admin)->post(route('devices.reload', $this->device))
            ->assertSessionHas('status', 'Reload sent to the 2 windows of Office PC.');
        $this->actingAs($this->admin)->post(route('devices.reload', $this->device))
            ->assertSessionHas('status', fn (string $s): bool => str_starts_with($s, 'A reload went out moments ago'));

        Event::assertDispatchedTimes(ReloadRequested::class, 1);
        Event::assertDispatched(ReloadRequested::class, fn (ReloadRequested $e): bool => $e->broadcastAs() === 'display.reload'
            && array_map(fn ($c): string => $c->name, $e->broadcastOn()) === $this->windows->map(fn (Display $d): string => 'private-display.'.$d->uuid)->all()
            && ! in_array('private-display.'.$other->uuid, array_map(fn ($c): string => $c->name, $e->broadcastOn()), true));
    });
});

describe('removing a device', function () {
    it('unpairs every window of it at once', function () {
        Event::fake([DisplayChanged::class]);
        TvPairing::factory()->confirmedFor($this->windows[0])->create();
        $other = Display::factory()->create();

        $this->actingAs($this->admin)->delete(route('devices.destroy', $this->device))
            ->assertRedirect()->assertSessionHas('status', 'Office PC removed; its 2 windows show a pairing code again.');

        expect(Device::query()->sole()->is($other->device))->toBeTrue()
            ->and(Display::query()->sole()->is($other))->toBeTrue()
            ->and(TvPairing::query()->count())->toBe(0)
            ->and(AdminAuditEntry::query()->sole()->only('action', 'subject'))->toBe(['action' => AdminAction::DisplayRemoved, 'subject' => 'Office PC']);
        Event::assertDispatchedTimes(DisplayChanged::class, 2);
        foreach ($this->windows as $window) {
            Event::assertDispatched(DisplayChanged::class, fn (DisplayChanged $e): bool => $e->broadcastAs() === 'display.removed'
                && $e->broadcastOn()->name === 'private-display.'.$window->uuid);
        }
    });
});

describe('who changes a device', function () {
    it('is team admins only', function (string $who) {
        $user = $who === 'member' ? $this->member : User::factory()->inTeam()->create();

        $this->actingAs($user)->put(route('devices.update', $this->device), ['name' => 'Mine'])->assertForbidden();
        $this->actingAs($user)->post(route('devices.reload', $this->device))->assertForbidden();
        $this->actingAs($user)->delete(route('devices.destroy', $this->device))->assertForbidden();

        expect($this->device->refresh()->name)->toBe('Office PC')->and(Display::query()->count())->toBe(2);
    })->with(['member', 'outsider']);
});
