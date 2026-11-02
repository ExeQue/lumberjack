<?php

declare(strict_types=1);

namespace App\Domains\Administration\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * A Lumberjack without any user sends every page to the first-run setup, where
 * the first administrator is created. Once someone exists, setup is gone.
 *
 * Whether setup is done is a plain file, storage/app/setup/complete (the
 * "setup" disk in config/filesystems.php), so no request has to ask the database and an operator
 * can delete it through the storage mount. Without the file the database is
 * asked once and the file written, so a lost file heals itself.
 */
class RedirectToSetup
{
    public function handle(Request $request, Closure $next): Response
    {
        if (self::complete() || $request->routeIs('setup', 'setup.store')) {
            return $next($request);
        }

        return redirect()->route('setup');
    }

    public const string FLAG = 'complete';

    /**
     * Whether the flag is set, without asking the database.
     */
    public static function flagged(): bool
    {
        return self::disk()->exists(self::FLAG);
    }

    public static function complete(): bool
    {
        if (self::flagged()) {
            return true;
        }

        if (! User::query()->exists()) {
            return false;
        }

        self::markComplete();

        return true;
    }

    public static function markComplete(): void
    {
        self::disk()->put(self::FLAG, now()->toIso8601String()."\nDelete this file to have Lumberjack check its setup again.\n");
    }

    public static function forget(): void
    {
        self::disk()->delete(self::FLAG);
    }

    private static function disk(): Filesystem
    {
        return Storage::disk('setup');
    }
}
