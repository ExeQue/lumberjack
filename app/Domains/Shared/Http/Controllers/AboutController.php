<?php

declare(strict_types=1);

namespace App\Domains\Shared\Http\Controllers;

use App\Domains\Shared\Support\AppVersion;
use App\Domains\Shared\Support\Changelog;
use App\Domains\Shared\Support\Contributors;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Which Lumberjack this is, where to read about it, and, for system administrators, what
 * it runs on: what a bug report needs.
 */
class AboutController
{
    public function __invoke(Request $request): Response
    {
        $version = AppVersion::version();
        $latest = AppVersion::latestRelease();

        return Inertia::render('about', [
            'version' => $version,
            'revision' => AppVersion::revision(),
            'release_url' => AppVersion::isRelease() ? AppVersion::releaseUrl($version) : null,
            'changelog_url' => AppVersion::REPOSITORY_URL.'/blob/main/CHANGELOG.md',
            'repository_url' => AppVersion::REPOSITORY_URL,
            'docs_url' => config('lumberjack.docs_url'),
            'changelog' => Inertia::defer(fn (): array => Changelog::releases()),
            // A checkout reads GitHub for them, so after the page and apart from the changelog.
            'contributors' => Inertia::defer(fn (): array => ['people' => Contributors::all(), 'checkout' => Contributors::built() === null], 'contributors'),
            'update' => $request->user()?->isAdmin() && config('lumberjack.update_check') ? [
                'latest' => $latest,
                'url' => $latest !== null ? AppVersion::releaseUrl($latest['version']) : null,
                'available' => AppVersion::updateAvailable() !== null,
            ] : null,
            // The stack, for an administrator filing a bug; nobody else needs to know it.
            'system' => $request->user()?->isAdmin() ? [
                'php' => PHP_VERSION,
                'laravel' => Application::VERSION,
                'database' => self::databaseVersion(),
                'database_size' => self::databaseSize(),
                'queue' => (string) config('queue.default'),
                'cache' => (string) config('cache.default'),
                'session' => (string) config('session.driver'),
                'environment' => app()->environment(),
            ] : null,
        ]);
    }

    private static function databaseSize(): ?string
    {
        if (DB::getDriverName() !== 'pgsql') {
            return null;
        }

        try {
            $size = DB::scalar('select pg_size_pretty(pg_database_size(current_database()))');
        } catch (Throwable) {
            return null;
        }

        return is_string($size) ? $size : null;
    }

    private static function databaseVersion(): ?string
    {
        try {
            $version = DB::scalar(DB::getDriverName() === 'pgsql' ? 'show server_version' : 'select version()');
        } catch (Throwable) {
            return null;
        }

        return is_string($version) ? DB::getDriverName().' '.$version : null;
    }
}
