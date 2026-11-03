<?php

declare(strict_types=1);

namespace App\Domains\Screens\Data;

use App\Domains\Screens\Enums\ReleaseScope;
use App\Domains\Shared\Enums\Period;

/**
 * What a data widget's figures cover: projects of the team (none means all of them),
 * an environment, the period ending now, a release, a filter on the source's route,
 * queue, job or host where * stands for anything, and for a route the domain.
 */
final readonly class WidgetScope
{
    /**
     * @param  list<string>  $projects  project uuids
     */
    public function __construct(
        public array $projects = [],
        public ?string $environment = null,
        public Period $period = Period::Day,
        public ReleaseScope $release = ReleaseScope::All,
        public ?string $filter = null,
        public ?string $domain = null,
    ) {
    }

    /**
     * @param  array<string, mixed>  $scope  as a widget stores it
     */
    public static function fromArray(array $scope): self
    {
        $string = fn (string $key): ?string => isset($scope[$key]) && is_string($scope[$key]) && $scope[$key] !== '' ? $scope[$key] : null;

        return new self(
            projects: array_values(array_filter((array) ($scope['projects'] ?? []), 'is_string')),
            environment: $string('environment'),
            period: Period::tryFrom((string) $string('period')) ?? Period::Day,
            release: ReleaseScope::tryFrom((string) $string('release')) ?? ReleaseScope::All,
            filter: $string('filter'),
            domain: $string('domain'),
        );
    }
}
