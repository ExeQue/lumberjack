<?php

declare(strict_types=1);

namespace App\Domains\Connectors\MobilePay\Data;

/**
 * A sales unit's API keys, as the business portal lists them: what an access token is
 * asked for with, and the two of them every call sends again.
 */
final readonly class MobilePayCredentials
{
    public function __construct(
        public string $clientId,
        public string $clientSecret,
        public string $subscriptionKey,
        public string $merchantSerialNumber,
    ) {
    }
}
