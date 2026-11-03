<?php

declare(strict_types=1);

namespace App\Domains\Shared\Support;

/**
 * A search term as a LIKE pattern: matches anywhere, * stands for anything, and
 * % and _ are searched for as themselves.
 */
final class LikePattern
{
    public static function contains(string $term): string
    {
        return '%'.str_replace('*', '%', addcslashes($term, '\\%_')).'%';
    }
}
