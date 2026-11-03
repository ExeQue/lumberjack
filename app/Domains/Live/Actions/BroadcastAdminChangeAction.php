<?php

declare(strict_types=1);

namespace App\Domains\Live\Actions;

use App\Domains\Live\Enums\AdminChangeKind;
use App\Domains\Live\Events\AdminChanged;

use function Illuminate\Support\defer;

use Throwable;

class BroadcastAdminChangeAction
{
    /** Deferred and sent once per kind, like BroadcastTeamChangeAction. */
    public function execute(AdminChangeKind $kind): void
    {
        defer(function () use ($kind): void {
            try {
                broadcast(new AdminChanged($kind))->toOthers();
            } catch (Throwable $e) {
                report($e);
            }
        }, "live:admin:{$kind->value}");
    }
}
