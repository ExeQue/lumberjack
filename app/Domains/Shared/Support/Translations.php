<?php

declare(strict_types=1);

namespace App\Domains\Shared\Support;

use Illuminate\Support\Facades\File;

/**
 * The UI strings the React pages read: every file in lang/{locale}/ui, keyed by
 * file name, with English filling any key a locale lacks.
 */
final class Translations
{
    /**
     * @return array<string, mixed>
     */
    public static function forFrontend(string $locale): array
    {
        $english = self::load('en');

        return $locale === 'en' ? $english : array_replace_recursive($english, self::load($locale));
    }

    /**
     * @return array<string, mixed>
     */
    private static function load(string $locale): array
    {
        $dir = lang_path($locale.'/ui');

        if (! is_dir($dir)) {
            return [];
        }

        $strings = [];

        foreach (File::files($dir) as $file) {
            if ($file->getExtension() === 'php') {
                $strings[$file->getFilenameWithoutExtension()] = require $file->getPathname();
            }
        }

        return $strings;
    }
}
