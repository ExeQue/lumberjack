<?php

declare(strict_types=1);

namespace App\Domains\Connectors\GitHub\Data;

/**
 * How to read one GitHub repository: its owner/name, the token that reads it, and the branch
 * its commits and CI are read from (null: the default branch).
 */
final readonly class GitHubCredentials
{
    public function __construct(
        public string $repository,
        public string $token,
        public ?string $branch = null,
    ) {
    }
}
