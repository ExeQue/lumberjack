<?php

declare(strict_types=1);

namespace App\Domains\Connectors\GitHub\Data;

/**
 * How to read one GitHub repository: its owner/name and the token that reads it.
 */
final readonly class GitHubCredentials
{
    public function __construct(
        public string $repository,
        public string $token,
    ) {
    }
}
