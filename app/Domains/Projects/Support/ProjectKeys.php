<?php

declare(strict_types=1);

namespace App\Domains\Projects\Support;

use Illuminate\Support\Str;

/**
 * The secret server token and the public browser key of a project.
 */
final class ProjectKeys
{
    public static function newServerToken(): string
    {
        return 'lj_srv_'.Str::random(40);
    }

    public static function newPullToken(): string
    {
        return 'lj_pull_'.Str::random(40);
    }

    public static function newPublicKey(): string
    {
        return 'pk_'.Str::lower(Str::random(24));
    }

    /**
     * The public stats key: in the URL of the metrics API, so it is long enough not to guess.
     */
    public static function newStatsKey(): string
    {
        return 'ps_'.Str::lower(Str::random(40));
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
