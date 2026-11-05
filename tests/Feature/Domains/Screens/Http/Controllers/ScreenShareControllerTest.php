<?php

declare(strict_types=1);

use App\Domains\Administration\Enums\AdminAction;
use App\Domains\Administration\Models\AdminAuditEntry;
use App\Domains\Screens\Actions\DuplicateScreenAction;
use App\Domains\Screens\Actions\ResolveScreenDataAction;
use App\Domains\Screens\Actions\RotateScreenShareKeyAction;
use App\Domains\Screens\Actions\SetScreenShareAction;
use App\Domains\Screens\Broadcasting\ScreenShareChannel;
use App\Domains\Screens\Events\ScreenChanged;
use App\Domains\Screens\Events\ScreenUnshared;
use App\Domains\Screens\Http\Controllers\ScreenShareController;
use App\Domains\Screens\Http\Controllers\SharedScreenController;
use App\Domains\Screens\Http\Requests\UpdateScreenShareRequest;
use App\Domains\Screens\Models\Screen;
use App\Domains\Teams\Enums\TeamRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;

covers(
    ScreenShareController::class,
    SharedScreenController::class,
    UpdateScreenShareRequest::class,
    SetScreenShareAction::class,
    RotateScreenShareKeyAction::class,
    DuplicateScreenAction::class,
    ScreenShareChannel::class,
    ScreenUnshared::class,
    ScreenChanged::class,
    Screen::class,
);

beforeEach(function () {
    $this->screen = Screen::factory()->create(['name' => 'Ops']);
    $this->admin = User::factory()->inTeam($this->screen->team, TeamRole::Admin)->create();
    $this->member = User::factory()->inTeam($this->screen->team, TeamRole::Member)->create();
    $this->share = function (bool $enabled = true, bool $titles = false): Screen {
        $this->actingAs($this->admin)->put(route('screens.share', $this->screen), ['enabled' => $enabled, 'include_titles' => $titles])->assertRedirect();

        return $this->screen->refresh();
    };
});

describe('turning a share link on and off', function () {
    it('makes an unguessable key, kept encrypted and found by its hash', function () {
        $screen = ($this->share)();

        expect($screen->share_key)->toMatch('/^ls_[a-z0-9]{40}$/')
            ->and($screen->share_key_hash)->toBe(hash('sha256', $screen->share_key))
            ->and(DB::table('screens')->where('id', $screen->id)->value('share_key'))->not->toContain($screen->share_key)
            ->and($screen->shareUrl())->toBe(url('/s/'.$screen->share_key))
            ->and($screen->share_titles)->toBeFalse()
            ->and(AdminAuditEntry::query()->sole()->action)->toBe(AdminAction::ScreenShared);
    });

    it('shows the link and its QR code in the designer, to change for admins only', function () {
        $screen = ($this->share)();

        $this->actingAs($this->member)->get(route('screens.edit', $screen))->assertInertia(fn (Assert $page) => $page
            ->where('sharing.url', $screen->shareUrl())
            ->where('sharing.can_manage', false)
            ->where('sharing.qr', fn (string $svg): bool => str_starts_with($svg, '<svg'))
            ->where('tv.url', route('tv'))
            ->where('tv.pair_url', route('pair')));
        $this->actingAs($this->member)->put(route('screens.share', $screen), ['enabled' => false])->assertForbidden();
        $this->actingAs($this->member)->post(route('screens.share.rotate', $screen))->assertForbidden();
    });

    it('changes whether titles show, and tells open viewers', function () {
        $screen = ($this->share)();
        Event::fake([ScreenChanged::class]);

        $screen = ($this->share)(true, true);
        ($this->share)(true, true);

        expect($screen->share_titles)->toBeTrue()
            ->and(AdminAuditEntry::query()->pluck('action')->all())->toBe([AdminAction::ScreenShared, AdminAction::ScreenShareChanged]);
        Event::assertDispatchedTimes(ScreenChanged::class, 1);
        Event::assertDispatched(ScreenChanged::class, fn (ScreenChanged $e): bool => $e->broadcastOn()[1]->name === 'screen-share.'.$screen->share_key_hash
            // The share channel is public: no uuid in the payload.
            && array_keys($e->broadcastWith()) === ['updated_at']);
    });

    it('turns it off: the link stops working and its viewers are told', function () {
        $screen = ($this->share)();
        $key = $screen->share_key;
        $hash = $screen->share_key_hash;
        Event::fake([ScreenUnshared::class]);

        $screen = ($this->share)(false);
        ($this->share)(false);

        expect($screen->share_key)->toBeNull()->and($screen->share_key_hash)->toBeNull();
        Event::assertDispatchedTimes(ScreenUnshared::class, 1);
        Event::assertDispatched(ScreenUnshared::class, fn (ScreenUnshared $e): bool => $e->broadcastOn()->name === 'screen-share.'.$hash
            && $e->broadcastAs() === 'screen.unshared' && $e->broadcastWith() === []);
        auth()->logout();
        $this->get('/s/'.$key)->assertNotFound()->assertInertia(fn (Assert $page) => $page->component('screens/unshared'));
        $this->getJson('/s/'.$key.'/data')->assertNotFound();
    });

    it('rotates the key: the old link stops working', function () {
        $screen = ($this->share)();
        $old = $screen->share_key;
        Event::fake([ScreenUnshared::class]);

        $this->actingAs($this->admin)->post(route('screens.share.rotate', $screen))->assertRedirect();

        $screen->refresh();
        expect($screen->share_key)->not->toBe($old)->toMatch('/^ls_[a-z0-9]{40}$/');
        Event::assertDispatched(ScreenUnshared::class, fn (ScreenUnshared $e): bool => $e->broadcastOn()->name === 'screen-share.'.hash('sha256', $old));
        $this->get('/s/'.$old)->assertNotFound();
        $this->get('/s/'.$screen->share_key)->assertOk();
    });

    it('leaves a duplicate unshared', function () {
        $screen = ($this->share)();

        $this->actingAs($this->admin)->post(route('screens.duplicate', $screen))->assertRedirect();

        $copy = Screen::query()->whereKeyNot($screen->id)->sole();
        expect($copy->share_key)->toBeNull()->and($copy->share_key_hash)->toBeNull()->and($copy->share_titles)->toBeFalse();
    });

    it('rotates nothing while the screen is not shared', function () {
        $this->actingAs($this->admin)->post(route('screens.share.rotate', $this->screen))->assertRedirect();

        expect($this->screen->refresh()->share_key)->toBeNull()->and(AdminAuditEntry::query()->count())->toBe(0);
    });

    it('needs to know whether to share', function () {
        $this->actingAs($this->admin)->put(route('screens.share', $this->screen), [])->assertSessionHasErrors('enabled');
    });
});


describe('a screen by its share link', function () {
    beforeEach(function () {
        $this->shared = ($this->share)();
        auth()->logout();
    });

    it('shows the kiosk without a session, framable and without a Referer', function () {
        $this->get('/s/'.$this->shared->share_key)->assertOk()
            ->assertHeader('Content-Security-Policy', 'frame-ancestors *')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertInertia(fn (Assert $page) => $page
                ->component('screens/kiosk')
                ->where('screen.uuid', $this->shared->uuid)
                ->where('share', true)
                ->where('channel', 'screen-share.'.$this->shared->share_key_hash)
                ->where('data_url', '/s/'.$this->shared->share_key.'/data'));
    });

    it('serves counts only, unless the team chose titles', function (bool $titles) {
        $this->shared->update(['share_titles' => $titles]);
        $this->mock(ResolveScreenDataAction::class)->shouldReceive('execute')
            ->withArgs(fn (Screen $s, ?string $widget, bool $aggregatesOnly): bool => $s->is($this->shared) && $widget === 'requests' && $aggregatesOnly === ! $titles)
            ->once()->andReturn([]);

        $this->getJson('/s/'.$this->shared->share_key.'/data?widget=requests')->assertOk()->assertJsonPath('data', []);
    })->with(['counts only' => false, 'with titles' => true]);

    it('knows no made up key', function () {
        $this->get('/s/ls_'.str_repeat('0', 40))->assertNotFound();
        $this->get('/s/not-a-key')->assertNotFound();
    });
});
