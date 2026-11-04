<?php

declare(strict_types=1);

namespace App\Domains\Shared\Support;

use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;

/**
 * The UI strings the React pages read: every file in lang/{locale}/ui, keyed by
 * file name, with English filling any key a locale lacks.
 */
final class Translations
{
    /** Locales the frontend may ask for: a folder name under lang/. */
    public const string LOCALE_PATTERN = '[a-z]{2}(_[A-Z]{2})?';

    /**
     * Where the browser fetches the strings: the version changes when a file of the
     * locale (or of English, which fills its gaps) changes, so the response can be
     * cached for good.
     */
    public static function url(string $locale): string
    {
        return route('i18n', ['locale' => $locale, 'v' => self::version($locale)], absolute: false);
    }

    /** A short fingerprint of the locale's files: their names, sizes and times. */
    public static function version(string $locale): string
    {
        $files = collect([...self::files('en'), ...($locale === 'en' ? [] : self::files($locale))])
            ->map(fn (SplFileInfo $file): string => $file->getFilename().':'.$file->getSize().':'.$file->getMTime());

        return substr(md5($files->implode('|')), 0, 12);
    }

    public static function exists(string $locale): bool
    {
        return is_dir(lang_path($locale.'/ui'));
    }

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

        foreach (self::files($locale) as $file) {
            $strings[$file->getFilenameWithoutExtension()] = require $file->getPathname();
        }

        return $strings;
    }

    /**
     * @return list<SplFileInfo>
     */
    private static function files(string $locale): array
    {
        $dir = lang_path($locale.'/ui');

        return is_dir($dir) ? array_values(array_filter(File::files($dir), fn (SplFileInfo $file): bool => $file->getExtension() === 'php')) : [];
    }
}
