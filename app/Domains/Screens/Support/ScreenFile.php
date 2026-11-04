<?php

declare(strict_types=1);

namespace App\Domains\Screens\Support;

use App\Domains\Health\Models\HealthCheck;

/**
 * The portable file a screen is exported to and imported from (see ExportScreenAction and
 * ImportScreenAction): {format, version, exported_at, screen}. Records of the team are
 * named by stable keys, not ids, so the file works in another team or Lumberjack: a project
 * by its slug, a health check by its project's slug, environment and slug (or name, for a
 * monitored check, which has no slug).
 */
final class ScreenFile
{
    public const string FORMAT = 'lumberjack-screen';

    public const int VERSION = 1;

    /** Largest file an import reads, in kilobytes. */
    public const int MAX_KILOBYTES = 256;

    /**
     * @return array{project: string, environment: string, slug?: string, name?: string}
     */
    public static function checkKey(HealthCheck $check, string $project): array
    {
        return [
            'project' => $project,
            'environment' => $check->environment,
            ...($check->slug !== null ? ['slug' => $check->slug] : ['name' => (string) $check->name]),
        ];
    }

    /**
     * How a check key reads in a notice: "shop: db".
     *
     * @param  array<mixed>  $key
     */
    public static function checkLabel(array $key): string
    {
        $project = is_string($key['project'] ?? null) ? $key['project'] : '?';
        $check = $key['slug'] ?? $key['name'] ?? '?';

        return $project.': '.(is_string($check) ? $check : '?');
    }

    /** A name fit for a file: "Ops wall" becomes ops-wall.lumberjack-screen.json. */
    public static function fileName(string $name): string
    {
        return (str($name)->slug()->toString() ?: 'screen').'.'.self::FORMAT.'.json';
    }
}
