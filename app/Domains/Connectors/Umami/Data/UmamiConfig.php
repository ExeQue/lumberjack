<?php

declare(strict_types=1);

namespace App\Domains\Connectors\Umami\Data;

/**
 * What a connection keeps to read one Umami website, stored encrypted: the website, and an
 * API key, or a login's user name and password.
 */
final readonly class UmamiConfig
{
    public function __construct(
        public string $websiteId,
        public ?string $username,
        public string $secret,
    ) {
    }

    /**
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            is_string($data['website_id'] ?? null) ? $data['website_id'] : '',
            is_string($data['username'] ?? null) && $data['username'] !== '' ? $data['username'] : null,
            is_string($data['secret'] ?? null) ? $data['secret'] : '',
        );
    }

    /**
     * @return array{website_id: string, username: string|null, secret: string}
     */
    public function toArray(): array
    {
        return ['website_id' => $this->websiteId, 'username' => $this->username, 'secret' => $this->secret];
    }

    public function withWebsite(string $websiteId): self
    {
        return new self($websiteId, $this->username, $this->secret);
    }
}
