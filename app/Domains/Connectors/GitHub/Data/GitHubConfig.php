<?php

declare(strict_types=1);

namespace App\Domains\Connectors\GitHub\Data;

use App\Domains\Connectors\GitHub\Enums\GitHubPermission;

/**
 * What a connection keeps to read one GitHub repository, stored encrypted: the repository,
 * a read-only fine-grained token, the optional permissions the token had when it was
 * last connected, and the branch its commits and CI are read from (null: the default branch).
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
        public ?string $branch = null,
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
            // A connection made before a branch could be chosen reads the default branch.
            is_string($data['branch'] ?? null) && $data['branch'] !== '' ? $data['branch'] : null,
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

    /** The branch a connection's config reads, null for the default branch. */
    public static function branchOf(mixed $config): ?string
    {
        return $config instanceof self ? $config->branch : null;
    }

    public function withBranch(?string $branch): self
    {
        return new self($this->repository, $this->token, $this->granted, $branch);
    }

    /**
     * @return array{repository: string, token: string, granted: list<string>, branch: string|null}
     */
    public function toArray(): array
    {
        return ['repository' => $this->repository, 'token' => $this->token, 'granted' => array_map(fn (GitHubPermission $permission): string => $permission->value, $this->granted), 'branch' => $this->branch];
    }
}
