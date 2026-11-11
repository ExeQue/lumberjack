<?php

declare(strict_types=1);

namespace App\Domains\Connectors\GitHub\Data;

use App\Domains\Connectors\GitHub\Enums\GitHubFigure;
use App\Domains\Connectors\GitHub\Enums\GitHubPermission;

/**
 * What a connection keeps to read one GitHub repository, stored encrypted: the repository,
 * a read-only fine-grained token, the optional permissions the token had when it was
 * last connected, the branch its commits and CI are read from (null: the default branch),
 * and the key figures its page shows or hides apart from their default, by figure.
 */
final readonly class GitHubConfig
{
    /**
     * @param  list<GitHubPermission>  $granted
     * @param  array<string, bool>  $figures  by GitHubFigure value
     */
    public function __construct(
        public string $repository,
        public string $token,
        public array $granted = [],
        public ?string $branch = null,
        public array $figures = [],
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
            is_array($data['granted'] ?? null) ? array_values(collect($data['granted'])->map(fn (mixed $value): ?GitHubPermission => is_string($value) ? GitHubPermission::tryFrom($value) : null)->filter()->all()) : [],
            // A connection made before a branch could be chosen reads the default branch.
            is_string($data['branch'] ?? null) && $data['branch'] !== '' ? $data['branch'] : null,
            // A connection made before the figures could be chosen shows each as by default.
            is_array($data['figures'] ?? null) ? array_filter($data['figures'], fn (mixed $shown, int|string $figure): bool => is_bool($shown) && GitHubFigure::tryFrom((string) $figure) !== null, ARRAY_FILTER_USE_BOTH) : [],
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

    /**
     * The key figures a connection's page shows: each as it was chosen, or else as by default
     * (stars only for a public repository), never one needing a permission the token lacks.
     *
     * @return list<GitHubFigure>
     */
    public static function figuresOf(mixed $config, bool $private): array
    {
        $granted = self::grantedBy($config);
        $chosen = $config instanceof self ? $config->figures : [];

        return array_values(array_filter(GitHubFigure::cases(), fn (GitHubFigure $figure): bool => ($chosen[$figure->value] ?? $figure->shownByDefault($private))
            && ($figure->permission() === null || in_array($figure->permission(), $granted, true))));
    }

    /**
     * @param  array<string, bool>  $figures
     */
    public function withSettings(?string $branch, array $figures): self
    {
        return new self($this->repository, $this->token, $this->granted, $branch, $figures);
    }

    /**
     * @return array{repository: string, token: string, granted: list<string>, branch: string|null, figures: array<string, bool>}
     */
    public function toArray(): array
    {
        return ['repository' => $this->repository, 'token' => $this->token, 'granted' => array_map(fn (GitHubPermission $permission): string => $permission->value, $this->granted), 'branch' => $this->branch, 'figures' => $this->figures];
    }
}
