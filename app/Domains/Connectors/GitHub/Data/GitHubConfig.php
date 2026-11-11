<?php

declare(strict_types=1);

namespace App\Domains\Connectors\GitHub\Data;

/**
 * What a connection keeps to read one GitHub repository, stored encrypted: the repository
 * and a read-only fine-grained token.
 */
final readonly class GitHubConfig
{
    public function __construct(
        public string $repository,
        public string $token,
    ) {
    }

    /**
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            is_string($data['repository'] ?? null) ? $data['repository'] : '',
            is_string($data['token'] ?? null) ? $data['token'] : '',
        );
    }

    /**
     * @return array{repository: string, token: string}
     */
    public function toArray(): array
    {
        return ['repository' => $this->repository, 'token' => $this->token];
    }
}
