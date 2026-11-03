<?php

declare(strict_types=1);

namespace App\Domains\Screens\Actions;

use App\Domains\Administration\Actions\RecordAdminAuditAction;
use App\Domains\Administration\Enums\AdminAction;
use App\Domains\Screens\Enums\DisplayTheme;
use App\Domains\Screens\Events\TvPairingConfirmed;
use App\Domains\Screens\Models\Display;
use App\Domains\Screens\Models\Screen;
use App\Domains\Screens\Models\TvPairing;
use App\Domains\Shared\Actions\Concerns\CanHaveReasons;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ConfirmTvPairingAction
{
    use CanHaveReasons;

    public function __construct(
        private readonly RecordAdminAuditAction $audit,
    ) {
    }

    /**
     * Pairs the TV showing the code as a display of the screen's team: the display is
     * made now, its token handed to the TV when it asks with its pairing cookie. Only a
     * team admin pairs, and a code works once. Null, with a reason, when refused.
     */
    public function execute(TvPairing $pairing, User $user, Screen $screen, string $name): ?Display
    {
        if (! $user->canAdministerTeam($screen->team)) {
            $this->addReason(__('screens.pairing.not_admin'));

            return null;
        }

        $display = DB::transaction(function () use ($pairing, $screen, $name): ?Display {
            $locked = TvPairing::query()->whereKey($pairing->id)->wherePending()->lockForUpdate()->first();

            if ($locked === null) {
                return null;
            }

            $display = Display::query()->create([
                'team_id' => $screen->team_id,
                'screen_id' => $screen->id,
                'name' => $name,
                'theme' => DisplayTheme::Default,
                'user_agent' => $locked->user_agent,
                'ip' => $locked->ip,
                'slot' => $locked->slot,
                'device_hash' => $locked->device_hash,
            ]);

            // The TV gets as long again to pick up its token.
            $locked->update(['display_id' => $display->id, 'confirmed_at' => now(), 'expires_at' => now()->addMinutes(TvPairing::LIFETIME_MINUTES)]);

            return $display;
        });

        if ($display === null) {
            $this->addReason(__('screens.pairing.expired'));

            return null;
        }

        $this->audit->execute($user, AdminAction::DisplayPaired, $display->name, ['display' => $display->uuid, 'screen' => $screen->uuid]);
        TvPairingConfirmed::dispatch($pairing);

        return $display;
    }
}
