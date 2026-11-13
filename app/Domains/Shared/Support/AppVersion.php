<?php

declare(strict_types=1);

namespace App\Domains\Shared\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Which Lumberjack this is: the release and commit the Docker image was built from, or,
 * in a checkout, what git says (asked once in a while, not on every request).
 */
final class AppVersion
{
    public const string LATEST_RELEASE_KEY = 'app-version:latest-release';

    /** Where Lumberjack's source lives. */
    public const string REPOSITORY_URL = 'https://github.com/ExeQue/lumberjack';

    private const int GIT_CACHE_SECONDS = 300;

    /** "0.1.5", or in a checkout e.g. "v0.1.4-298-g9885421"; "dev" when nothing says. */
    public static function version(): string
    {
        $built = config('lumberjack.version');

        if (is_string($built) && $built !== '') {
            return $built;
        }

        return self::git('describe --tags --always') ?? 'dev';
    }

    /** The commit, short; null when unknown. */
    public static function revision(): ?string
    {
        $built = config('lumberjack.revision');

        if (is_string($built) && $built !== '') {
            return substr($built, 0, 12);
        }

        return self::git('rev-parse --short=12 HEAD');
    }

    /** True for a tagged release (built from v1.2.3), false for a build in between. */
    public static function isRelease(): bool
    {
        return preg_match('/^v?\d+\.\d+\.\d+$/', self::version()) === 1;
    }

    public static function releaseUrl(string $version): string
    {
        return self::REPOSITORY_URL.'/releases/tag/v'.ltrim($version, 'v');
    }

    /**
     * The newest release the last check found; null before the first check or with checks off.
     *
     * @return array{version: string, checked_at: string}|null
     */
    public static function latestRelease(): ?array
    {
        if (! config('lumberjack.update_check')) {
            return null;
        }

        /** @var array{version: string, checked_at: string}|null $latest */
        $latest = Cache::get(self::LATEST_RELEASE_KEY);

        return $latest;
    }

    /** The newer release to move to; null when up to date, unknown, or on a build between releases. */
    public static function updateAvailable(): ?string
    {
        $latest = self::latestRelease();

        return $latest !== null && self::isRelease() && version_compare($latest['version'], ltrim(self::version(), 'v'), '>')
            ? $latest['version']
            : null;
    }

    private static function git(string $command): ?string
    {
        if (! is_dir(base_path('.git'))) {
            return null;
        }

        return Cache::remember('app-version:'.md5($command), self::GIT_CACHE_SECONDS, function () use ($command): ?string {
            try {
                $result = Process::path(base_path())->timeout(3)->run('git '.$command);
            } catch (Throwable) {
                return null;
            }

            $output = trim($result->output());

            return $result->successful() && $output !== '' ? $output : null;
        });
    }
}
