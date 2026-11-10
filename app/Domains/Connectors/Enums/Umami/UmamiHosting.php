<?php

declare(strict_types=1);

namespace App\Domains\Connectors\Enums\Umami;

/**
 * Where an Umami instance runs, which decides its API's address and how it is signed in to:
 * Umami Cloud takes an API key only, a self-hosted instance an API key or a login.
 */
enum UmamiHosting: string
{
    case Cloud = 'cloud';
    case SelfHosted = 'self_hosted';

    public const string CLOUD_API = 'https://api.umami.is/v1';

    /**
     * The API's base address: fixed for the cloud, the instance's own followed by /api else.
     */
    public function apiUrl(?string $instance): string
    {
        return match ($this) {
            self::Cloud => self::CLOUD_API,
            self::SelfHosted => rtrim((string) preg_replace('~/api/?$~', '', rtrim(trim((string) $instance), '/')), '/').'/api',
        };
    }

    public static function fromApiUrl(string $url): self
    {
        return $url === self::CLOUD_API ? self::Cloud : self::SelfHosted;
    }

    /** The address a person knows the instance by: the API's without /api. */
    public static function instanceUrl(string $apiUrl): ?string
    {
        return $apiUrl === self::CLOUD_API ? null : (string) preg_replace('~/api$~', '', $apiUrl);
    }
}
