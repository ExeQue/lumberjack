<?php

declare(strict_types=1);

namespace App\Domains\Connectors\Umami\Data;

/**
 * How to reach an Umami instance's API: Umami Cloud and an API key, or a self-hosted
 * instance with an API key or a login. With a user name the secret is the password.
 */
final readonly class UmamiCredentials
{
    public function __construct(
        public string $baseUrl,
        public ?string $username,
        public string $secret,
    ) {
    }

    public function usesLogin(): bool
    {
        return $this->username !== null && $this->username !== '';
    }

    /** Names these credentials in a cache key without holding them. */
    public function fingerprint(): string
    {
        return hash('sha256', $this->baseUrl."\0".$this->username."\0".$this->secret);
    }
}
