<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Drivers\Concerns;

use App\Domains\Alerts\Exceptions\AlertDeliveryFailedException;
use App\Domains\Alerts\Models\NotificationChannel;
use App\Domains\Health\Exceptions\HealthExceptionInterface;
use App\Domains\Health\Support\NetworkGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * HTTP for a channel: a short timeout, retries with backoff when the other side is
 * briefly away, and a delivery failure that says what it answered.
 */
trait SendsHttp
{
    /** Waits between attempts, in milliseconds: four attempts within about 8 seconds. */
    private const array BACKOFF_MS = [500, 2000, 5000];

    protected function http(): PendingRequest
    {
        return Http::timeout(10)->retry(
            times: self::BACKOFF_MS,
            when: fn (Throwable $e): bool => self::worthRetrying($e),
            throw: false,
        );
    }

    /**
     * A dropped connection, a rate limit or a server error may pass; anything else, such
     * as a removed webhook (404) or a rejected message (400), will not.
     */
    private static function worthRetrying(Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        $status = $e instanceof RequestException ? $e->response->status() : 0;

        return $status === 429 || $status >= 500;
    }

    /**
     * Sends the request. With $pinTo, a URL anyone on the team may set, it must resolve
     * to public addresses only (unless alerts.webhooks.allow_private) and the request
     * connects to the address that was checked, so a rebinding DNS answer cannot turn it
     * inward; redirects are not followed.
     *
     * @param  callable(PendingRequest): Response  $request
     */
    protected function deliver(NotificationChannel $channel, callable $request, ?string $pinTo = null): void
    {
        $http = $this->http();

        if ($pinTo !== null) {
            try {
                $http = $http->withoutRedirecting()->withOptions(app(NetworkGuard::class)->pin($pinTo, (bool) config('alerts.webhooks.allow_private', false)));
            } catch (HealthExceptionInterface) {
                throw AlertDeliveryFailedException::notPublic($channel->name);
            }
        }

        try {
            $response = $request($http);
        } catch (ConnectionException) {
            throw AlertDeliveryFailedException::unreachable($channel->name);
        }

        if ($response->failed()) {
            throw AlertDeliveryFailedException::status($channel->name, $response->status());
        }
    }
}
