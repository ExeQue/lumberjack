<?php

declare(strict_types=1);

namespace App\Domains\Connectors\GitHub\Data;

use App\Domains\Connectors\GitHub\Enums\GitHubPermission;

/**
 * What a connection keeps to read one GitHub repository, stored encrypted: the repository,
 * a read-only fine-grained token, and the optional permissions the token had when it was
 * last connected.
 */
final readonly class GitHubConfig
{
    /**
     * @param  list<GitHubPermission>  $granted
     */
    public function __construct(
        public string $repository,
        public string $token,
        public array $granted = [],
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
            // A connection made before optional permissions were asked has none.
            is_array($data['granted'] ?? null) ? array_values(array_filter(array_map(fn (mixed $value): ?GitHubPermission => is_string($value) ? GitHubPermission::tryFrom($value) : null, $data['granted']))) : [],
        );
    }

    /**
     * The optional permissions a connection's config says its token has.
     *
     * @return list<GitHubPermission>
     */
    public static function grantedBy(mixed $config): array
    {
        return $config instanceof self ? $config->granted : [];
    }

    /**
     * @return array{repository: string, token: string, granted: list<string>}
     */
    public function toArray(): array
    {
        return ['repository' => $this->repository, 'token' => $this->token, 'granted' => array_map(fn (GitHubPermission $permission): string => $permission->value, $this->granted)];
    }
}
