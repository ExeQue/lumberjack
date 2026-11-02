<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Support;

use App\Domains\Alerts\Contracts\ChannelDriver;
use App\Domains\Alerts\Enums\ChannelType;
use App\Domains\Alerts\Exceptions\UnknownChannelTypeException;
use Illuminate\Contracts\Container\Container;

class ChannelDriverRegistry
{
    public function __construct(
        private readonly Container $container,
    ) {
    }

    public function for(ChannelType $type): ChannelDriver
    {
        $class = config('alerts.drivers.'.$type->value);

        if (! is_string($class) || ! is_a($class, ChannelDriver::class, true)) {
            throw UnknownChannelTypeException::forType($type->value);
        }

        /** @var ChannelDriver */
        return $this->container->make($class);
    }
}
