<?php

declare(strict_types=1);

namespace App\Domains\Screens\Actions;

use App\Domains\Administration\Actions\RecordAdminAuditAction;
use App\Domains\Administration\Enums\AdminAction;
use App\Domains\Screens\Events\ScreenChanged;
use App\Domains\Screens\Events\ScreenUnshared;
use App\Domains\Screens\Models\Screen;
use App\Domains\Screens\Support\TvCredentials;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Turns a screen's share link on (with a new key), off (the link stops working and its
 * open viewers say so), or changes whether it shows titles and names or only counts.
 * Every change goes in the audit log.
 */
class SetScreenShareAction
{
    public function __construct(
        private readonly RecordAdminAuditAction $audit,
    ) {
    }

    public function execute(Screen $screen, User $user, bool $enabled, bool $includeTitles): Screen
    {
        $wasShared = $screen->share_key_hash !== null;

        if (! $enabled) {
            if ($wasShared) {
                $hash = (string) $screen->share_key_hash;
                $screen->update(['share_key' => null, 'share_key_hash' => null, 'share_titles' => false]);
                $this->audit->execute($user, AdminAction::ScreenUnshared, $screen->name, ['screen' => $screen->uuid]);
                ScreenUnshared::dispatch($hash);
            }

            return $screen;
        }

        if ($wasShared && $screen->share_titles === $includeTitles) {
            return $screen;
        }

        $screen->fill(['share_titles' => $includeTitles]);

        if (! $wasShared) {
            $screen->fill(self::newKey());
        }

        $screen->save();
        $this->audit->execute($user, $wasShared ? AdminAction::ScreenShareChanged : AdminAction::ScreenShared, $screen->name, ['screen' => $screen->uuid, 'include_titles' => $includeTitles]);

        if ($wasShared) {
            ScreenChanged::dispatch($screen);
        }

        return $screen;
    }

    /**
     * A share key, in the link's URL: long enough not to guess, stored encrypted and
     * found by its sha256.
     *
     * @return array{share_key: string, share_key_hash: string}
     */
    public static function newKey(): array
    {
        $key = 'ls_'.Str::lower(Str::random(40));

        return ['share_key' => $key, 'share_key_hash' => TvCredentials::hash($key)];
    }
}
