<?php

declare(strict_types=1);

namespace App\Domains\Screens\Actions;

use App\Domains\Administration\Actions\RecordAdminAuditAction;
use App\Domains\Administration\Enums\AdminAction;
use App\Domains\Screens\Enums\DisplayTheme;
use App\Domains\Screens\Events\TvPairingConfirmed;
use App\Domains\Screens\Models\Device;
use App\Domains\Screens\Models\Display;
use App\Domains\Screens\Models\Screen;
use App\Domains\Screens\Models\TvPairing;
use App\Domains\Screens\Support\DeviceName;
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
     * Pairs the window showing the code as a display of the screen's team: the first window
     * of a browser makes its device (named $name), any later one (same device cookie, same
     * team) joins that device, and a window that was paired before and lost its cookie gets
     * its display back. Its token goes to the TV when it asks with its pairing cookie. Only
     * a team admin pairs, and a code works once. Null, with a reason, when refused.
     */
    public function execute(TvPairing $pairing, User $user, Screen $screen, ?string $name = null): ?Display
    {
        if (! $user->canAdministerTeam($screen->team)) {
            $this->addReason(__('screens.pairing.not_admin'));

            return null;
        }

        $display = DB::transaction(function () use ($pairing, $user, $screen, $name): ?Display {
            $locked = TvPairing::query()->whereKey($pairing->id)->wherePending()->lockForUpdate()->first();

            if ($locked === null) {
                return null;
            }

            $device = $this->device($locked, $screen->team_id);
            $device ??= Device::query()->create([
                'team_id' => $screen->team_id,
                'name' => $name !== null && $name !== '' ? $name : (DeviceName::from($locked->user_agent) ?? __('screens.pairing.default_name')),
                'device_hash' => $locked->device_hash,
                'created_by' => $user->id,
            ]);
            $device->update(['user_agent' => $locked->user_agent, 'ip' => $locked->ip]);

            $display = Display::query()->where('device_id', $device->id)->where('slot', $locked->slot)->first();
            $display ??= new Display(['team_id' => $device->team_id, 'device_id' => $device->id, 'slot' => $locked->slot, 'theme' => DisplayTheme::Default]);
            $display->fill(['screen_id' => $screen->id, 'playlist' => null])->save();
            $display->setRelation('device', $device);

            // The TV gets as long again to pick up its token.
            $locked->update(['display_id' => $display->id, 'confirmed_at' => now(), 'expires_at' => now()->addMinutes(TvPairing::LIFETIME_MINUTES)]);

            return $display;
        });

        if ($display === null) {
            $this->addReason(__('screens.pairing.expired'));

            return null;
        }

        $this->audit->execute($user, AdminAction::DisplayPaired, $display->fullName(), ['display' => $display->uuid, 'screen' => $screen->uuid]);
        TvPairingConfirmed::dispatch($pairing);

        return $display;
    }

    /** The pairing browser's device in the team, locked while the window joins it. */
    private function device(TvPairing $pairing, int $teamId): ?Device
    {
        return $pairing->device_hash === null ? null : Device::query()->whereTeam($teamId)->whereDeviceHash($pairing->device_hash)->lockForUpdate()->first();
    }
}
