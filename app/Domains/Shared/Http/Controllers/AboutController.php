<?php

declare(strict_types=1);

namespace App\Domains\Shared\Http\Controllers;

use App\Domains\Shared\Support\AppVersion;
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
            'changelog_url' => 'https://github.com/ExeQue/lumberjack/blob/main/CHANGELOG.md',
            'docs_url' => config('lumberjack.docs_url'),
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
                'queue' => (string) config('queue.default'),
                'cache' => (string) config('cache.default'),
                'session' => (string) config('session.driver'),
                'environment' => app()->environment(),
            ] : null,
        ]);
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
