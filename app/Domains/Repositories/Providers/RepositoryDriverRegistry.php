<?php

declare(strict_types=1);

namespace App\Domains\Repositories\Providers;

use App\Domains\Repositories\Contracts\RepositoryDriver;
use App\Domains\Repositories\Enums\RepositoryProvider;
use Illuminate\Contracts\Container\Container;

class RepositoryDriverRegistry
{
    public function __construct(
        private readonly Container $container,
    ) {
    }

    public function for(RepositoryProvider $provider): RepositoryDriver
    {
        /** @var RepositoryDriver */
        return $this->container->make(match ($provider) {
            RepositoryProvider::GitHub => GitHubDriver::class,
        });
    }
}
