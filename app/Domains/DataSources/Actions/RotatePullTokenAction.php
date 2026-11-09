<?php

declare(strict_types=1);

namespace App\Domains\DataSources\Actions;

use App\Domains\DataSources\Models\DataSourceConnection;
use App\Domains\Projects\Support\ProjectKeys;
use Illuminate\Support\Facades\Crypt;

/**
 * Makes a new pull token. The one the app holds is kept and pulls go on with it until the
 * app answers to the new one, also if this is rotated again before that.
 */
class RotatePullTokenAction
{
    public function execute(DataSourceConnection $connection): string
    {
        $token = ProjectKeys::newPullToken();
        $held = $connection->activeToken();

        // Not through the model: its dirty check would decrypt a token saved under another APP_KEY.
        DataSourceConnection::query()->whereKey($connection->id)->update([
            'pull_token' => Crypt::encryptString($token),
            'pull_token_previous' => $held !== null ? Crypt::encryptString($held) : null,
            'uses_previous' => $held !== null,
            'next_check_at' => null,
        ]);

        return $token;
    }
}
