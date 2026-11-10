<?php

declare(strict_types=1);

namespace App\Domains\Connectors\Mosparo\Data;

/**
 * What a connection keeps to read one mosparo project, stored encrypted: its API keys.
 */
final readonly class MosparoConfig
{
    public function __construct(
        public string $publicKey,
        public string $privateKey,
    ) {
    }

    /**
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            is_string($data['public_key'] ?? null) ? $data['public_key'] : '',
            is_string($data['private_key'] ?? null) ? $data['private_key'] : '',
        );
    }

    /**
     * @return array{public_key: string, private_key: string}
     */
    public function toArray(): array
    {
        return ['public_key' => $this->publicKey, 'private_key' => $this->privateKey];
    }
}
