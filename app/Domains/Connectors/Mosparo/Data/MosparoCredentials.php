<?php

declare(strict_types=1);

namespace App\Domains\Connectors\Mosparo\Data;

/**
 * How to reach one mosparo project's API: the instance and the project's key pair.
 */
final readonly class MosparoCredentials
{
    public function __construct(
        public string $host,
        public string $publicKey,
        public string $privateKey,
    ) {
    }
}
