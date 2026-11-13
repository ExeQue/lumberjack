<?php

declare(strict_types=1);

namespace App\Domains\Connectors\Packagist\Data;

/**
 * What a connection keeps to read one Packagist package: its name. Packagist is public, so
 * there are no credentials.
 */
final readonly class PackagistConfig
{
    public function __construct(
        public string $package,
    ) {
    }

    /**
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(is_string($data['package'] ?? null) ? $data['package'] : '');
    }

    /**
     * @return array{package: string}
     */
    public function toArray(): array
    {
        return ['package' => $this->package];
    }
}
