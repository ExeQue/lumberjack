<?php

declare(strict_types=1);

namespace App\Domains\Administration\Actions;

use App\Domains\Shared\Support\AppVersion;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Asks GitHub for the latest release and remembers it. A failed check keeps the last answer.
 */
final class CheckForUpdateAction
{
    private const string LATEST_RELEASE_URL = 'https://api.github.com/repos/ExeQue/lumberjack/releases/latest';

    public function execute(): bool
    {
        if (! config('lumberjack.update_check')) {
            return false;
        }

        try {
            $tag = Http::acceptJson()->withUserAgent('Lumberjack')->connectTimeout(3)->timeout(5)
                ->get(self::LATEST_RELEASE_URL)->json('tag_name');
        } catch (ConnectionException) {
            return false;
        }

        if (! is_string($tag) || preg_match('/^v?\d+\.\d+\.\d+$/', $tag) !== 1) {
            return false;
        }

        Cache::forever(AppVersion::LATEST_RELEASE_KEY, [
            'version' => ltrim($tag, 'v'),
            'checked_at' => now()->toIso8601String(),
        ]);

        return true;
    }
}
