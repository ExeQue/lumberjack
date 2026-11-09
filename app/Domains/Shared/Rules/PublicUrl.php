<?php

declare(strict_types=1);

namespace App\Domains\Shared\Rules;

use App\Domains\Health\Exceptions\HealthExceptionInterface;
use App\Domains\Health\Support\NetworkGuard;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An http(s) URL whose host resolves only to public addresses, so a webhook
 * cannot be pointed at this server or the network behind it. The same guard as
 * health checks; sending pins the address again (see SendsHttp). Local networks
 * can be allowed with alerts.webhooks.allow_private, e.g. for development.
 */
final class PublicUrl implements ValidationRule
{
    /**
     * @param  bool|null  $allowPrivate  null takes alerts.webhooks.allow_private; connected apps pass health.data_sources_allow_private
     */
    public function __construct(
        private readonly ?bool $allowPrivate = null,
    ) {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::allows($value, $this->allowPrivate)) {
            $fail(__('alerts.webhook_not_public'));
        }
    }

    public static function allows(string $url, ?bool $allowPrivate = null): bool
    {
        try {
            app(NetworkGuard::class)->pin($url, $allowPrivate ?? (bool) config('alerts.webhooks.allow_private', false));
        } catch (HealthExceptionInterface) {
            return false;
        }

        return true;
    }
}
