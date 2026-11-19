<?php

declare(strict_types=1);

namespace App\Domains\Performance\Enums;

/**
 * What kind of request the SDK saw, beyond its route. An ordinary request (a first page
 * load, an API call) has none.
 */
enum RequestKind: string
{
    /** An Inertia visit answered with a page object. */
    case Inertia = 'inertia';

    /** An Inertia partial reload: only some props, such as deferred ones. */
    case InertiaPartial = 'inertia-partial';

    /** An Inertia visit prefetched before the user asked for it. */
    case InertiaPrefetch = 'inertia-prefetch';

    /** A Livewire update; the route is the component and method it called. */
    case Livewire = 'livewire';

    /** The kind an event names, or null for an ordinary request or one a newer SDK knows of. */
    public static function fromEvent(mixed $value): ?self
    {
        return is_string($value) ? self::tryFrom($value) : null;
    }
}
