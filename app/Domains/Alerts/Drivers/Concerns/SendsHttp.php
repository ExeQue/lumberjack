<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Drivers\Concerns;

use App\Domains\Alerts\Exceptions\AlertDeliveryFailedException;
use App\Domains\Alerts\Models\NotificationChannel;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * HTTP for a channel: a short timeout, a few retries on a dropped connection, and a
 * delivery failure that says what the other side answered.
 */
trait SendsHttp
{
    protected function http(): PendingRequest
    {
        return Http::timeout(10)->retry(
            times: 3,
            sleepMilliseconds: 200,
            when: fn (Throwable $e): bool => $e instanceof ConnectionException,
            throw: false,
        );
    }

    /**
     * @param  callable(PendingRequest): Response  $request
     */
    protected function deliver(NotificationChannel $channel, callable $request): void
    {
        try {
            $response = $request($this->http());
        } catch (ConnectionException) {
            throw AlertDeliveryFailedException::unreachable($channel->name);
        }

        if ($response->failed()) {
            throw AlertDeliveryFailedException::status($channel->name, $response->status());
        }
    }
}
