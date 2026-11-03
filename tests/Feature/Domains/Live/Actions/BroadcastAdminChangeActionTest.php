<?php

declare(strict_types=1);

use App\Domains\Live\Actions\BroadcastAdminChangeAction;
use App\Domains\Live\Enums\AdminChangeKind;
use App\Domains\Live\Events\AdminChanged;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Event;

covers(BroadcastAdminChangeAction::class, AdminChanged::class);

describe('admin broadcasts', function () {
    it('tells the administrators\' channel what changed, once per kind', function () {
        Event::fake([AdminChanged::class]);
        $action = app(BroadcastAdminChangeAction::class);

        $action->execute(AdminChangeKind::Users);
        $action->execute(AdminChangeKind::Users);
        app(DeferredCallbackCollection::class)->invoke();

        Event::assertDispatchedTimes(AdminChanged::class, 1);
        Event::assertDispatched(AdminChanged::class, fn (AdminChanged $event): bool => $event->broadcastOn()->name === 'private-admin'
            && $event->broadcastAs() === 'users.changed'
            && $event->broadcastWith() === ['kind' => 'users.changed']);
    });
});
