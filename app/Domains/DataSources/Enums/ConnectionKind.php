<?php

declare(strict_types=1);

namespace App\Domains\DataSources\Enums;

/**
 * Who a connection pulls from: an app with the Laravel package, signed with a pull token,
 * or an Umami website, read through Umami's API with the credentials it was given.
 */
enum ConnectionKind: string
{
    case App = 'app';
    case Umami = 'umami';
}
