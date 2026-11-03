<?php

declare(strict_types=1);

namespace App\Domains\Issues\Support;

use App\Domains\Issues\Models\Issue;
use App\Models\User;
use Illuminate\Support\Facades\URL;

/**
 * The signed link in every watching mail that stops watching without signing in.
 */
final class WatchLinks
{
    public const int VALID_DAYS = 180;

    public static function stop(Issue $issue, User $user): string
    {
        return URL::temporarySignedRoute('watching.stop', now()->addDays(self::VALID_DAYS), ['issue' => $issue->uuid, 'user' => $user->uuid]);
    }
}
